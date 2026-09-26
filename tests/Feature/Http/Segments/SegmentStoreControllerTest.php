<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-06: GET /segments/create (the form) and POST /segments (its submit). Behind auth + segments.create.
| `rule` always goes through RuleValidator (SegmentService::create()) before it is ever persisted —
| never re-validated ad hoc here. `name` is unique case-insensitively (segments_name_unique).
*/

const VALID_RULE = ['field' => 'total_orders', 'operator' => '>=', 'value' => 1];

function segDeny(User $user, string $module, string $action): void
{
    $permission = Permission::query()->where('module', $module)->where('action', $action)->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);
}

// ================================================================== GET create

it('answers a guest asking for the create form with a redirect to login', function () {
    $this->get('/segments/create')->assertRedirect(route('login'));
});

it('forbids a user without segments.create the create form', function () {
    $this->actingAs(Fx::userWith('segments.view'))->get('/segments/create')->assertForbidden();
});

it('renders the create form with the whitelist', function () {
    $response = $this->actingAs(Fx::userWith('segments.create'))->get('/segments/create');

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('segments/create')
        ->has('whitelist.fields')
        ->has('whitelist.operators')
        ->has('whitelist.limits'));
});

// ================================================================== POST store

it('answers a guest posting a new segment with a redirect to login and writes nothing', function () {
    $this->post('/segments', ['name' => 'x', 'rule' => VALID_RULE]);

    expect(Segment::query()->count())->toBe(0);
});

it('forbids a user without segments.create and writes nothing', function () {
    $this->actingAs(Fx::userWith('segments.view'))
        ->post('/segments', ['name' => 'مشتریان جدید', 'rule' => VALID_RULE])
        ->assertForbidden();

    expect(Segment::query()->count())->toBe(0);
});

it('lets an explicit deny on segments.create beat the role grant', function () {
    $user = Fx::userWith('segments.create');
    segDeny($user, 'segments', 'create');

    $this->actingAs($user)->post('/segments', ['name' => 'مشتریان جدید', 'rule' => VALID_RULE])->assertForbidden();
    expect(Segment::query()->count())->toBe(0);
});

it('creates a dynamic segment, redirects to its detail page, and audits it', function () {
    $user = Fx::userWith('segments.create');

    $response = $this->actingAs($user)->post('/segments', [
        'name' => 'مشتریان پرارزش', 'description' => 'توضیح', 'rule' => VALID_RULE,
    ]);

    $segment = Segment::query()->firstOrFail();
    $response->assertRedirect(route('segments.show', $segment));

    expect($segment->name)->toBe('مشتریان پرارزش')
        ->and($segment->description)->toBe('توضیح')
        ->and($segment->type->value)->toBe('dynamic')
        ->and($segment->rule)->toEqual(VALID_RULE)
        ->and($segment->created_by)->toBe($user->id)
        ->and($segment->is_system)->toBeFalse();

    $audit = DB::table('audit_logs')->where('action', 'segment.created')->first();
    expect($audit)->not->toBeNull()
        ->and((int) $audit->auditable_id)->toBe($segment->id);
});

it('rejects a missing name and a missing rule with validation errors, and writes nothing', function () {
    $user = Fx::userWith('segments.create');

    $this->actingAs($user)->post('/segments', ['rule' => VALID_RULE])
        ->assertSessionHasErrors(['name']);
    $this->actingAs($user)->post('/segments', ['name' => 'x'])
        ->assertSessionHasErrors(['rule']);

    expect(Segment::query()->count())->toBe(0);
});

it('rejects a duplicate name, case-insensitively, and writes nothing', function () {
    Segment::factory()->create(['name' => 'مشتریان وفادار']);
    $user = Fx::userWith('segments.create');

    $this->actingAs($user)
        ->post('/segments', ['name' => 'مشتریان وفادار', 'rule' => VALID_RULE])
        ->assertSessionHasErrors(['name']);

    expect(Segment::query()->count())->toBe(1);
});

it('rejects a field outside the whitelist with a Persian error and writes nothing', function () {
    $user = Fx::userWith('segments.create');

    $this->actingAs($user)->post('/segments', [
        'name' => 'قانون بد', 'rule' => ['field' => 'email', 'operator' => '=', 'value' => 'x'],
    ])->assertSessionHasErrors(['rule']);

    expect(Segment::query()->count())->toBe(0);
});

it('rejects a classic SQL injection payload used as a field name and writes nothing', function () {
    $user = Fx::userWith('segments.create');

    $this->actingAs($user)->post('/segments', [
        'name' => 'قانون مخرب',
        'rule' => ['field' => "'; DROP TABLE customers; --", 'operator' => '=', 'value' => 1],
    ])->assertSessionHasErrors(['rule']);

    expect(Segment::query()->count())->toBe(0)
        ->and(Schema::hasTable('customers'))->toBeTrue();
});
