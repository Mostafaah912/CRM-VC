<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-06: GET /segments/{segment}/edit (the form) and POST /segments/{segment} (its submit). Behind
| auth + segments.edit. An is_system segment (P5-07's seeds) is entirely locked — SegmentService::modify()
| refuses regardless of permission.
*/

const OTHER_RULE = ['field' => 'total_orders', 'operator' => '>=', 'value' => 2];

function segDenyUpd(User $user, string $module, string $action): void
{
    $permission = Permission::query()->where('module', $module)->where('action', $action)->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);
}

// ================================================================== GET edit

it('answers a guest asking for the edit form with a redirect to login', function () {
    $segment = Segment::factory()->create();

    $this->get("/segments/{$segment->id}/edit")->assertRedirect(route('login'));
});

it('forbids a user without segments.edit', function () {
    $segment = Segment::factory()->create();

    $this->actingAs(Fx::userWith('segments.view'))->get("/segments/{$segment->id}/edit")->assertForbidden();
});

it('renders the edit form pre-filled with the segment and the whitelist', function () {
    $segment = Segment::factory()->create(['name' => 'قدیمی', 'rule' => OTHER_RULE]);

    $response = $this->actingAs(Fx::userWith('segments.edit'))->get("/segments/{$segment->id}/edit");

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('segments/edit')
        ->where('segment.name', 'قدیمی')
        ->where('segment.rule', OTHER_RULE)
        ->where('segment.is_system', false)
        ->has('whitelist.fields'));
});

// ================================================================== POST update

it('answers a guest posting an update with a redirect to login and changes nothing', function () {
    $segment = Segment::factory()->create(['name' => 'اصلی']);

    $this->post("/segments/{$segment->id}", ['name' => 'تغییر', 'rule' => OTHER_RULE]);

    expect($segment->refresh()->name)->toBe('اصلی');
});

it('forbids a user without segments.edit and changes nothing', function () {
    $segment = Segment::factory()->create(['name' => 'اصلی']);

    $this->actingAs(Fx::userWith('segments.view'))
        ->post("/segments/{$segment->id}", ['name' => 'تغییر', 'rule' => OTHER_RULE])
        ->assertForbidden();

    expect($segment->refresh()->name)->toBe('اصلی');
});

it('lets an explicit deny on segments.edit beat the role grant', function () {
    $segment = Segment::factory()->create(['name' => 'اصلی']);
    $user = Fx::userWith('segments.edit');
    segDenyUpd($user, 'segments', 'edit');

    $this->actingAs($user)->post("/segments/{$segment->id}", ['name' => 'تغییر', 'rule' => OTHER_RULE])->assertForbidden();
    expect($segment->refresh()->name)->toBe('اصلی');
});

it('updates name, description and rule, redirects to the detail page, and audits it', function () {
    $segment = Segment::factory()->create(['name' => 'قدیمی', 'description' => 'قدیم']);
    $user = Fx::userWith('segments.edit');

    $response = $this->actingAs($user)->post("/segments/{$segment->id}", [
        'name' => 'جدید', 'description' => 'جدید', 'rule' => OTHER_RULE,
    ]);

    $response->assertRedirect(route('segments.show', $segment));
    $segment->refresh();

    expect($segment->name)->toBe('جدید')
        ->and($segment->description)->toBe('جدید')
        ->and($segment->rule)->toEqual(OTHER_RULE);

    $audit = DB::table('audit_logs')->where('action', 'segment.updated')->first();
    expect($audit)->not->toBeNull()->and((int) $audit->auditable_id)->toBe($segment->id);
});

it('rejects a duplicate name shared with another segment, case-insensitively, but allows keeping its own name', function () {
    Segment::factory()->create(['name' => 'دیگری']);
    $segment = Segment::factory()->create(['name' => 'خودش']);
    $user = Fx::userWith('segments.edit');

    $this->actingAs($user)
        ->post("/segments/{$segment->id}", ['name' => 'دیگری', 'rule' => OTHER_RULE])
        ->assertSessionHasErrors(['name']);
    expect($segment->refresh()->name)->toBe('خودش');

    $this->actingAs($user)
        ->post("/segments/{$segment->id}", ['name' => 'خودش', 'rule' => OTHER_RULE])
        ->assertRedirect(route('segments.show', $segment));
});

it('rejects an invalid rule and changes nothing', function () {
    $segment = Segment::factory()->create(['rule' => OTHER_RULE]);
    $user = Fx::userWith('segments.edit');

    $this->actingAs($user)
        ->post("/segments/{$segment->id}", ['name' => 'اسم', 'rule' => ['field' => 'email', 'operator' => '=', 'value' => 'x']])
        ->assertSessionHasErrors(['rule']);

    expect($segment->refresh()->rule)->toEqual(OTHER_RULE);
});

it('refuses to edit an is_system segment — even with segments.edit — and changes nothing', function () {
    $segment = Segment::factory()->create(['name' => 'سیستمی', 'is_system' => true]);
    $user = Fx::userWith('segments.edit');

    $this->actingAs($user)
        ->post("/segments/{$segment->id}", ['name' => 'تغییر', 'rule' => OTHER_RULE])
        ->assertSessionHasErrors(['segment']);

    expect($segment->refresh()->name)->toBe('سیستمی');
});
