<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerEvent;
use App\Modules\Customers\Models\CustomerNote;
use App\Modules\Customers\Services\CustomerNotesService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P3-05 — CustomerNotesService: a note and its timeline event are ONE unit. store() and destroy() each write the note change and one
| customer_events row inside one transaction, so a failure of either leaves neither; the event payload is {note_id} only;
| deletion is the author's (while they hold customers.note) or a customers.manage_notes holder's; nothing is logged.
*/

beforeEach(function () {
    config(['logging.default' => 'null']);
    $this->customer = Customer::factory()->create();
    $this->author = Fx::userWith('customers.view', 'customers.note');
});

// Model listeners are static and would leak into every later test.
afterEach(function () {
    CustomerEvent::flushEventListeners();
    CustomerNote::flushEventListeners();
});

function nsService(): CustomerNotesService
{
    return app(CustomerNotesService::class);
}

function nsNote(Customer $customer, User $author, string $body = 'a note'): CustomerNote
{
    return CustomerNote::query()->create(['customer_id' => $customer->id, 'user_id' => $author->id, 'body' => $body]);
}

// ================================================================== store: one transaction

it('stores the note and its note_added event together, and returns the note', function () {
    $note = nsService()->store($this->customer, $this->author, '  hello  ');

    $event = CustomerEvent::query()->firstOrFail();

    expect($note->body)->toBe('hello')
        ->and($note->user_id)->toBe($this->author->id)
        ->and(CustomerNote::query()->count())->toBe(1)
        ->and($event->event_type->value)->toBe('note_added')
        ->and($event->customer_id)->toBe($this->customer->id)
        ->and($event->payload)->toBe(['note_id' => $note->id])
        ->and($event->happened_at->diffInSeconds(now(), true))->toBeLessThan(30);
});

it('writes the store event INSIDE a transaction of its own (a savepoint over the test\'s outer one)', function () {
    $level = null;
    $outer = DB::transactionLevel();
    CustomerEvent::creating(function () use (&$level) {
        $level = DB::transactionLevel();
    });

    nsService()->store($this->customer, $this->author, 'hello');

    expect($level)->toBe($outer + 1);
});

it('leaves NO note behind when the event cannot be written — the two are one unit', function () {
    CustomerEvent::creating(function () {
        throw new RuntimeException('event insert failed');
    });

    expect(fn () => nsService()->store($this->customer, $this->author, 'hello'))->toThrow(RuntimeException::class, 'event insert failed');

    expect(CustomerNote::query()->count())->toBe(0)->and(DB::table('customer_events')->count())->toBe(0);
});

it('leaves NO event behind when the note cannot be written', function () {
    CustomerNote::creating(function () {
        throw new RuntimeException('note insert failed');
    });

    expect(fn () => nsService()->store($this->customer, $this->author, 'hello'))->toThrow(RuntimeException::class, 'note insert failed');

    expect(CustomerNote::query()->count())->toBe(0)->and(DB::table('customer_events')->count())->toBe(0);
});

// ================================================================== destroy: one transaction

it('deletes the note and writes its note_deleted event together', function () {
    $note = nsNote($this->customer, $this->author);

    nsService()->destroy($note, $this->author);

    $event = CustomerEvent::query()->firstOrFail();

    expect(CustomerNote::query()->count())->toBe(0)
        ->and($event->event_type->value)->toBe('note_deleted')
        ->and($event->customer_id)->toBe($this->customer->id)
        ->and($event->payload)->toBe(['note_id' => $note->id]);
});

it('writes the delete event INSIDE a transaction of its own', function () {
    $note = nsNote($this->customer, $this->author);
    $level = null;
    $outer = DB::transactionLevel();
    CustomerEvent::creating(function () use (&$level) {
        $level = DB::transactionLevel();
    });

    nsService()->destroy($note, $this->author);

    expect($level)->toBe($outer + 1);
});

it('keeps the note when the delete event cannot be written — no note is lost without a trace', function () {
    $note = nsNote($this->customer, $this->author);
    CustomerEvent::creating(function () {
        throw new RuntimeException('event insert failed');
    });

    expect(fn () => nsService()->destroy($note, $this->author))->toThrow(RuntimeException::class, 'event insert failed');

    expect(CustomerNote::query()->count())->toBe(1)->and(DB::table('customer_events')->count())->toBe(0);
});

it('leaves NO event behind when the note cannot be deleted', function () {
    $note = nsNote($this->customer, $this->author);
    CustomerNote::deleting(function () {
        throw new RuntimeException('note delete failed');
    });

    expect(fn () => nsService()->destroy($note, $this->author))->toThrow(RuntimeException::class, 'note delete failed');

    expect(CustomerNote::query()->count())->toBe(1)->and(DB::table('customer_events')->count())->toBe(0);
});

// ================================================================== who may delete

it('lets the author delete while they hold customers.note, and a customers.manage_notes holder delete anyone\'s', function () {
    nsService()->destroy(nsNote($this->customer, $this->author), $this->author);
    nsService()->destroy(nsNote($this->customer, $this->author), Fx::userWith('customers.manage_notes'));

    expect(CustomerNote::query()->count())->toBe(0)->and(DB::table('customer_events')->count())->toBe(2);
});

it('refuses everyone else with an AuthorizationException, and changes nothing', function (array $keys) {
    $note = nsNote($this->customer, $this->author);

    expect(fn () => nsService()->destroy($note, Fx::userWith(...$keys)))->toThrow(AuthorizationException::class);
    expect(CustomerNote::query()->count())->toBe(1)->and(DB::table('customer_events')->count())->toBe(0);
})->with([
    'no permission at all' => [[]],
    'view only' => [['customers.view']],
    'note holder who is not the author' => [['customers.view', 'customers.note']],
    'other manage-ish permissions' => [['customers.export', 'customers.anonymize', 'customers.view_full_phone']],
]);

it('refuses an author who no longer holds customers.note', function () {
    $author = Fx::userWith('customers.view');
    $note = nsNote($this->customer, $author);

    expect(fn () => nsService()->destroy($note, $author))->toThrow(AuthorizationException::class);
    expect(CustomerNote::query()->count())->toBe(1);
});

// ================================================================== input backstops

it('refuses a blank or too-long body itself, whatever the request let through', function (string $body) {
    expect(fn () => nsService()->store($this->customer, $this->author, $body))->toThrow(ValidationException::class);

    expect(CustomerNote::query()->count())->toBe(0)->and(DB::table('customer_events')->count())->toBe(0);
})->with(['empty' => [''], 'blank' => ["  \n\t "], '2001 ascii' => [str_repeat('x', 2001)], '2001 persian' => [str_repeat('ی', 2001)]]);

it('refuses to add a note to a soft-deleted customer', function () {
    $this->customer->delete();

    expect(fn () => nsService()->store($this->customer, $this->author, 'hello'))->toThrow(NotFoundHttpException::class);
    expect(CustomerNote::query()->count())->toBe(0);
});

// ================================================================== the list

it('pages the notes with the author loaded — three queries: the customer, the notes, their authors', function () {
    foreach (range(1, 5) as $i) {
        nsNote($this->customer, $this->author, "note {$i}");
    }
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });

    $page = nsService()->pageFor($this->customer->id)->toArray();

    expect($page['data'])->toHaveCount(5)->and($page['data'][0]['author_name'])->toBe($this->author->name)->and($sql)->toHaveCount(3);
});

// ================================================================== no logging

it('logs nothing: not a body, not a name, not an id', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message;
    });

    $note = nsService()->store($this->customer, $this->author, 'SECRET-NOTE-BODY');
    nsService()->pageFor($this->customer->id);
    nsService()->destroy($note, $this->author);

    expect($logged)->toBe([]);
});
