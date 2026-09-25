<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-06: GET /segments/{segment} — the detail page (PRD §25): name/description/rule summary,
| member_count, last_evaluated_at, evaluate/export actions, and a paginated, phone-masked member list.
| Behind auth + segments.view. A soft-deleted segment is a 404.
*/

it('answers a guest with a redirect to login', function () {
    $segment = Segment::factory()->create();

    $this->get("/segments/{$segment->id}")->assertRedirect(route('login'));
});

it('forbids a user without segments.view', function () {
    $segment = Segment::factory()->create();

    $this->actingAs(Fx::userWith())->get("/segments/{$segment->id}")->assertForbidden();
});

it('answers 404 for a soft-deleted segment, a missing one, and a non-number', function () {
    $segment = Segment::factory()->create();
    $viewer = Fx::userWith('segments.view');
    $segment->delete();

    $this->actingAs($viewer)->get("/segments/{$segment->id}")->assertNotFound();
    $this->actingAs($viewer)->get('/segments/987654321')->assertNotFound();
    $this->actingAs($viewer)->get('/segments/abc')->assertNotFound();
});

it('shows the segment\'s detail and its member list, phone masked', function () {
    $segment = Segment::factory()->create(['name' => 'VIP', 'member_count' => 1]);
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567', 'display_name' => 'Ann']);
    DB::table('segment_members')->insert(['segment_id' => $segment->id, 'customer_id' => $customer->id, 'added_at' => now()]);

    $response = $this->actingAs(Fx::userWith('segments.view'))->get("/segments/{$segment->id}");

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('segments/show')
        ->where('segment.name', 'VIP')
        ->where('segment.member_count', 1)
        ->where('members.data.0.display_name', 'Ann')
        ->where('members.data.0.phone', '********4567'));
    expect($response->getContent())->not->toContain('989121234567');
});

it('paginates the member list at 25 per page', function () {
    $segment = Segment::factory()->create();
    $customers = Customer::factory()->count(30)->create();

    DB::table('segment_members')->insert($customers->map(fn (Customer $c) => [
        'segment_id' => $segment->id, 'customer_id' => $c->id, 'added_at' => now(),
    ])->all());

    $response = $this->actingAs(Fx::userWith('segments.view'))->get("/segments/{$segment->id}");

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('members.total', 30)
        ->has('members.data', 25));
});
