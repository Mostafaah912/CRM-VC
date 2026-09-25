<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\Permission;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-06: DELETE /segments/{segment} — soft-delete. Behind auth + segments.delete (Owner/Manager only;
| the matrix withholds delete from Analyst). An is_system segment (P5-07's seeds) can never be deleted.
*/

function segDenyDel(User $user, string $module, string $action): void
{
    $permission = Permission::query()->where('module', $module)->where('action', $action)->firstOrFail();
    $user->permissionOverrides()->create(['permission_id' => $permission->id, 'effect' => 'deny']);
}

it('answers a guest with a redirect to login and deletes nothing', function () {
    $segment = Segment::factory()->create();

    $this->delete("/segments/{$segment->id}")->assertRedirect(route('login'));
    expect(Segment::query()->count())->toBe(1);
});

it('forbids a user without segments.delete and deletes nothing', function () {
    $segment = Segment::factory()->create();

    $this->actingAs(Fx::userWith('segments.view', 'segments.edit'))
        ->delete("/segments/{$segment->id}")
        ->assertForbidden();
    expect(Segment::query()->count())->toBe(1);
});

it('lets an explicit deny on segments.delete beat the role grant', function () {
    $segment = Segment::factory()->create();
    $user = Fx::userWith('segments.delete');
    segDenyDel($user, 'segments', 'delete');

    $this->actingAs($user)->delete("/segments/{$segment->id}")->assertForbidden();
    expect(Segment::query()->count())->toBe(1);
});

it('soft-deletes a segment, redirects to the list, and audits it', function () {
    $segment = Segment::factory()->create();
    $user = Fx::userWith('segments.delete');

    $this->actingAs($user)->delete("/segments/{$segment->id}")->assertRedirect(route('segments.index'));

    expect(Segment::query()->count())->toBe(0)
        ->and(Segment::withTrashed()->count())->toBe(1);

    $audit = DB::table('audit_logs')->where('action', 'segment.deleted')->first();
    expect($audit)->not->toBeNull()->and((int) $audit->auditable_id)->toBe($segment->id);
});

it('refuses to delete an is_system segment — even with segments.delete — and it stays', function () {
    $segment = Segment::factory()->create(['is_system' => true]);
    $user = Fx::userWith('segments.delete');

    $response = $this->actingAs($user)->delete("/segments/{$segment->id}");

    $response->assertSessionHasErrors(['segment']);
    expect(Segment::query()->count())->toBe(1);
});

it('answers 404 for a missing segment and a non-number', function () {
    $user = Fx::userWith('segments.delete');

    $this->actingAs($user)->delete('/segments/987654321')->assertNotFound();
    $this->actingAs($user)->delete('/segments/abc')->assertNotFound();
});
