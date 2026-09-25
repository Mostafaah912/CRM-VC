<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-05 — notes on Customer 360:
|   GET    /customers/{customer}/notes            customers.view                      the list, newest first, cursor-paged
|   POST   /customers/{customer}/notes {body}     customers.view + customers.note     201; note + `note_added` event in one transaction
|   DELETE /customers/{customer}/notes/{note}     customers.view; author (with customers.note) or customers.manage_notes; else 403;
|                                                 204; note + `note_deleted` event in one transaction
| The event payload is {"note_id": n} — NEVER the body, because a timeline event is shown to every customers.view holder.
*/

const NOTE_BODY = 'SECRET-NOTE-BODY: prefers evening calls';

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create(['display_name' => 'ZZ-Notes-Name', 'email' => 'zz-notes@example.test']);
});

function nteAuthor(): User
{
    return Fx::userWith('customers.view', 'customers.note');
}

function nteNote(Customer|int $customer, User $author, string $body = NOTE_BODY, ?string $at = null): CustomerNote
{
    $note = CustomerNote::query()->create(['customer_id' => $customer instanceof Customer ? $customer->id : $customer, 'user_id' => $author->id, 'body' => $body]);

    if ($at !== null) {
        DB::table('customer_notes')->where('id', $note->id)->update(['created_at' => $at]);
    }

    return $note;
}

function nteStore($test, User $user, Customer|int $customer, array $data): TestResponse
{
    $id = $customer instanceof Customer ? $customer->id : $customer;

    return $test->actingAs($user)->postJson("/customers/{$id}/notes", $data);
}

function nteDelete($test, User $user, Customer|int $customer, int|string $note): TestResponse
{
    $id = $customer instanceof Customer ? $customer->id : $customer;

    return $test->actingAs($user)->deleteJson("/customers/{$id}/notes/{$note}");
}

function nteDeny(User $user, string $action): void
{
    $permission = Permission::query()->where('module', 'customers')->where('action', $action)->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);
}

/** @return list<array<string, mixed>> */
function nteEvents(): array
{
    return DB::table('customer_events')->orderBy('id')->get()->map(fn (object $r) => (array) $r)->all();
}

// ================================================================== GET the list

it('lists a customer\'s notes, newest first, with the author\'s name and id and both dates — and no email, customer id or other field', function () {
    $ann = User::factory()->create(['name' => 'Ann Author', 'email' => 'ann-secret@example.test']);
    Fx::userWith('customers.view'); // a different user, unrelated
    $old = nteNote($this->customer, $ann, 'older', '2026-03-01 10:00:00+00');
    $new = nteNote($this->customer, $ann, 'newer', '2026-03-20 20:30:00+00'); // 1405/01/01 00:00 Tehran

    $response = $this->actingAs(Fx::userWith('customers.view'))->getJson("/customers/{$this->customer->id}/notes")->assertOk();

    expect($response->json('data'))->toBe([
        ['id' => $new->id, 'author_id' => $ann->id, 'author_name' => 'Ann Author', 'body' => 'newer', 'created_at_jalali' => '1405/01/01 00:00:00', 'created_at_iso' => '2026-03-20T20:30:00Z'],
        ['id' => $old->id, 'author_id' => $ann->id, 'author_name' => 'Ann Author', 'body' => 'older', 'created_at_jalali' => '1404/12/10 13:30:00', 'created_at_iso' => '2026-03-01T10:00:00Z'],
    ])->and(array_keys($response->json()))->toBe(['data', 'next_cursor', 'has_more'])
        ->and($response->getContent())->not->toContain('ann-secret@example.test')->not->toContain('customer_id')->not->toContain('zz-notes@example.test');
});

it('refuses a viewer without customers.view the notes list', function (array $keys) {
    nteNote($this->customer, nteAuthor());

    $response = $this->actingAs(Fx::userWith(...$keys))->getJson("/customers/{$this->customer->id}/notes");

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('SECRET-NOTE-BODY');
})->with(['nothing' => [[]], 'note only' => [['customers.note']], 'manage_notes only' => [['customers.manage_notes']]]);

it('answers a guest asking for the notes with 401', function () {
    $this->getJson("/customers/{$this->customer->id}/notes")->assertUnauthorized();
});

it('answers 404 for a soft-deleted customer, a missing one, and a non-number; 403 first without permission', function () {
    nteNote($this->customer, nteAuthor());
    $viewer = Fx::userWith('customers.view');
    $this->customer->delete();

    $this->actingAs($viewer)->getJson("/customers/{$this->customer->id}/notes")->assertNotFound();
    $this->actingAs($viewer)->getJson('/customers/987654321/notes')->assertNotFound();
    $this->actingAs($viewer)->getJson('/customers/abc/notes')->assertNotFound();
    $this->actingAs(Fx::userWith())->getJson('/customers/987654321/notes')->assertForbidden();
});

it('walks 60 notes in pages of 25, 25 and 10 — each once, newest first — and honours per_page and refuses a bad cursor', function () {
    $author = nteAuthor();

    foreach (range(1, 60) as $i) {
        nteNote($this->customer, $author, "note {$i}", sprintf('2026-03-%02d 10:00:00+00', 1 + intdiv($i, 3)));
    }
    $viewer = Fx::userWith('customers.view');
    $expected = DB::table('customer_notes')->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();

    $ids = [];
    $sizes = [];
    $cursor = null;

    do {
        $page = $this->actingAs($viewer)->getJson("/customers/{$this->customer->id}/notes".($cursor === null ? '' : "?cursor={$cursor}"))->assertOk()->json();
        $sizes[] = count($page['data']);
        array_push($ids, ...array_column($page['data'], 'id'));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($sizes) < 10);

    expect($sizes)->toBe([25, 25, 10])->and($ids)->toBe($expected);
    $this->actingAs($viewer)->getJson("/customers/{$this->customer->id}/notes?per_page=51")->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    $this->actingAs($viewer)->getJson("/customers/{$this->customer->id}/notes?cursor=garbage")->assertUnprocessable()->assertJsonValidationErrors(['cursor']);
});

it('lists only this customer\'s notes', function () {
    $author = nteAuthor();
    nteNote(Customer::factory()->create(), $author, 'OTHER-CUSTOMER-NOTE');
    nteNote($this->customer, $author, 'mine');

    $response = $this->actingAs(Fx::userWith('customers.view'))->getJson("/customers/{$this->customer->id}/notes")->assertOk();

    expect(array_column($response->json('data'), 'body'))->toBe(['mine'])->and($response->getContent())->not->toContain('OTHER-CUSTOMER-NOTE');
});

// ================================================================== POST store

it('answers a guest writing a note with 401', function () {
    $this->postJson("/customers/{$this->customer->id}/notes", ['body' => NOTE_BODY])->assertUnauthorized();
});

it('refuses a viewer who lacks customers.view or customers.note — one is not enough — and writes nothing', function (array $keys) {
    $response = nteStore($this, Fx::userWith(...$keys), $this->customer, ['body' => NOTE_BODY]);

    $response->assertForbidden();
    expect(DB::table('customer_notes')->count())->toBe(0)->and(DB::table('customer_events')->count())->toBe(0);
})->with([
    'nothing' => [[]],
    'view only — an Analyst-style viewer may not write' => [['customers.view']],
    'note only' => [['customers.note']],
    'manage_notes and reveal' => [['customers.manage_notes', 'customers.view_full_phone']],
]);

it('lets an explicit deny on customers.note beat the role grant', function () {
    $user = nteAuthor();
    nteDeny($user, 'note');

    nteStore($this, $user, $this->customer, ['body' => NOTE_BODY])->assertForbidden();
    expect(DB::table('customer_notes')->count())->toBe(0);
});

it('answers 403 — not 404 — to a viewer without permission who writes to an id that does not exist', function () {
    nteStore($this, Fx::userWith('customers.view'), 987_654_321, ['body' => NOTE_BODY])->assertForbidden();
});

it('answers 404 to a permitted viewer for a soft-deleted customer, a missing one and a non-number, and writes nothing', function () {
    $author = nteAuthor();
    $this->customer->delete();

    nteStore($this, $author, $this->customer, ['body' => NOTE_BODY])->assertNotFound();
    nteStore($this, $author, 987_654_321, ['body' => NOTE_BODY])->assertNotFound();
    $this->actingAs($author)->postJson('/customers/abc/notes', ['body' => NOTE_BODY])->assertNotFound();
    expect(DB::table('customer_notes')->count())->toBe(0)->and(DB::table('customer_events')->count())->toBe(0);
});

it('stores a note: 201, the note as the list shows it, the author taken from the session — never from the request', function () {
    $author = nteAuthor();
    $someoneElse = User::factory()->create();

    $response = nteStore($this, $author, $this->customer, ['body' => NOTE_BODY, 'user_id' => $someoneElse->id, 'author_id' => $someoneElse->id, 'customer_id' => 999_999]);

    $response->assertCreated();
    $note = CustomerNote::query()->firstOrFail();

    expect($note->user_id)->toBe($author->id)
        ->and($note->customer_id)->toBe($this->customer->id)
        ->and($note->body)->toBe(NOTE_BODY)
        ->and($response->json())->toBe([
            'id' => $note->id, 'author_id' => $author->id, 'author_name' => $author->name, 'body' => NOTE_BODY,
            'created_at_jalali' => $response->json('created_at_jalali'), 'created_at_iso' => $response->json('created_at_iso'),
        ])->and($response->json('created_at_iso'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('writes exactly one note_added event with payload {note_id} and the body NOWHERE in customer_events', function () {
    $author = nteAuthor();

    $note = CustomerNote::query()->find(nteStore($this, $author, $this->customer, ['body' => NOTE_BODY])->assertCreated()->json('id'));
    $events = nteEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0]['customer_id'])->toBe($this->customer->id)
        ->and($events[0]['event_type'])->toBe('note_added')
        ->and(json_decode($events[0]['payload'], true))->toBe(['note_id' => $note->id])
        ->and(json_encode($events))->not->toContain('SECRET-NOTE-BODY')->not->toContain('evening');
});

it('shows the note event on the timeline WITHOUT its text — the timeline\'s allowlist drops even the id', function () {
    $author = nteAuthor();
    nteStore($this, $author, $this->customer, ['body' => NOTE_BODY])->assertCreated();

    $response = $this->actingAs(Fx::userWith('customers.view'))->getJson("/customers/{$this->customer->id}/timeline")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.event_type'))->toBe('note_added')
        ->and($response->json('data.0.payload'))->toBeNull()
        ->and($response->getContent())->not->toContain('SECRET-NOTE-BODY');
});

it('trims the body and stores text as text — markup is kept verbatim as data (the page escapes it)', function () {
    $author = nteAuthor();

    nteStore($this, $author, $this->customer, ['body' => "  \n<script>alert(1)</script> & \"quotes\"\n  "])->assertCreated();

    expect(CustomerNote::query()->firstOrFail()->body)->toBe('<script>alert(1)</script> & "quotes"');
});

it('accepts a body of 1 and of 2000 characters — counted as characters, so Persian text is not penalised', function () {
    $author = nteAuthor();

    nteStore($this, $author, $this->customer, ['body' => 'x'])->assertCreated();
    nteStore($this, $author, $this->customer, ['body' => str_repeat('ی', 2000)])->assertCreated();

    expect(DB::table('customer_notes')->count())->toBe(2)->and(DB::table('customer_events')->count())->toBe(2);
});

it('refuses a bad body as a validation error and writes nothing', function (array $data) {
    nteStore($this, nteAuthor(), $this->customer, $data)->assertUnprocessable()->assertJsonValidationErrors(['body']);

    expect(DB::table('customer_notes')->count())->toBe(0)->and(DB::table('customer_events')->count())->toBe(0);
})->with([
    'missing' => [[]],
    'empty' => [['body' => '']],
    'spaces' => [['body' => '     ']],
    'newlines' => [['body' => "\n\n\t\n"]],
    'null' => [['body' => null]],
    'an array' => [['body' => ['x']]],
    'a number' => [['body' => 12345]],
]);

it('refuses a body of 2001 characters, ASCII or Persian', function (string $char) {
    nteStore($this, nteAuthor(), $this->customer, ['body' => str_repeat($char, 2001)])->assertUnprocessable()->assertJsonValidationErrors(['body']);

    expect(DB::table('customer_notes')->count())->toBe(0);
})->with(['ascii' => ['x'], 'persian' => ['ی']]);

// ================================================================== DELETE

it('answers a guest with 401 and a viewer without customers.view with 403 — even for the note\'s own author', function () {
    $author = nteAuthor();
    $note = nteNote($this->customer, $author);

    $this->deleteJson("/customers/{$this->customer->id}/notes/{$note->id}")->assertUnauthorized();

    $noView = Fx::userWith('customers.note', 'customers.manage_notes');
    nteDelete($this, $noView, $this->customer, $note->id)->assertForbidden();
    expect(CustomerNote::query()->count())->toBe(1);
});

it('lets the author delete their own note: 204, the note gone, exactly one note_deleted event with payload {note_id}', function () {
    $author = nteAuthor();
    $note = nteNote($this->customer, $author);

    nteDelete($this, $author, $this->customer, $note->id)->assertNoContent();
    $events = nteEvents();

    expect(CustomerNote::query()->count())->toBe(0)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['event_type'])->toBe('note_deleted')
        ->and($events[0]['customer_id'])->toBe($this->customer->id)
        ->and(json_decode($events[0]['payload'], true))->toBe(['note_id' => $note->id])
        ->and(json_encode($events))->not->toContain('SECRET-NOTE-BODY');
});

it('refuses a user who is neither the author nor a customers.manage_notes holder: 403, the note stays, no event', function () {
    $note = nteNote($this->customer, nteAuthor());
    $other = nteAuthor(); // holds view + note, but did not write it

    $response = nteDelete($this, $other, $this->customer, $note->id);

    $response->assertForbidden();
    expect(CustomerNote::query()->count())->toBe(1)->and(DB::table('customer_events')->count())->toBe(0)
        ->and($response->getContent())->not->toContain('SECRET-NOTE-BODY');
});

it('refuses a plain viewer who only holds customers.view', function () {
    $note = nteNote($this->customer, nteAuthor());

    nteDelete($this, Fx::userWith('customers.view'), $this->customer, $note->id)->assertForbidden();
    expect(CustomerNote::query()->count())->toBe(1);
});

it('lets a customers.manage_notes holder delete someone else\'s note', function () {
    $note = nteNote($this->customer, nteAuthor());
    $manager = Fx::userWith('customers.view', 'customers.manage_notes');

    nteDelete($this, $manager, $this->customer, $note->id)->assertNoContent();

    expect(CustomerNote::query()->count())->toBe(0)->and(nteEvents())->toHaveCount(1);
});

it('lets an explicit deny on customers.manage_notes beat the role grant — deny always wins', function () {
    $note = nteNote($this->customer, nteAuthor());
    $manager = Fx::userWith('customers.view', 'customers.manage_notes');
    nteDeny($manager, 'manage_notes');

    nteDelete($this, $manager, $this->customer, $note->id)->assertForbidden();
    expect(CustomerNote::query()->count())->toBe(1);
});

it('does not let an author who has since lost customers.note delete even their own note', function () {
    $author = Fx::userWith('customers.view'); // wrote it while they held customers.note; that grant is gone
    $note = nteNote($this->customer, $author);

    nteDelete($this, $author, $this->customer, $note->id)->assertForbidden();
    expect(CustomerNote::query()->count())->toBe(1);
});

it('does not delete a note through another customer\'s URL: 404 and the note stays', function () {
    $author = nteAuthor();
    $other = Customer::factory()->create();
    $note = nteNote($other, $author);

    nteDelete($this, $author, $this->customer, $note->id)->assertNotFound();

    expect(CustomerNote::query()->count())->toBe(1)->and(DB::table('customer_events')->count())->toBe(0);
});

it('answers 404 for a missing note, a non-numeric note id, a soft-deleted customer — and a second delete of the same note', function () {
    $author = nteAuthor();
    $note = nteNote($this->customer, $author);

    nteDelete($this, $author, $this->customer, 987_654_321)->assertNotFound();
    nteDelete($this, $author, $this->customer, 'abc')->assertNotFound();
    nteDelete($this, $author, $this->customer, $note->id)->assertNoContent();
    nteDelete($this, $author, $this->customer, $note->id)->assertNotFound();

    $second = nteNote($this->customer, $author);
    $this->customer->delete();
    nteDelete($this, $author, $this->customer, $second->id)->assertNotFound();

    expect(CustomerNote::query()->count())->toBe(1)->and(DB::table('customer_events')->count())->toBe(1);
});

it('answers 403 — not 404 — to a viewer without customers.view who deletes a note that does not exist', function () {
    nteDelete($this, Fx::userWith(), $this->customer, 987_654_321)->assertForbidden();
});
