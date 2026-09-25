<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Segments\Models\Segment;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-06: GET /segments/{segment}/export — the audited CSV export built in P5-04 (SegmentService::export()).
| Gated on customers.export at the route level too (not just inside the Service), so a plain browser
| navigation without permission gets a normal 403, not a JSON-only error.
*/

it('answers a guest with a redirect to login', function () {
    $segment = Segment::factory()->create();

    $this->get("/segments/{$segment->id}/export")->assertRedirect(route('login'));
});

it('forbids a user without customers.export', function () {
    $segment = Segment::factory()->create();

    $this->actingAs(Fx::userWith('segments.view'))->get("/segments/{$segment->id}/export")->assertForbidden();
});

it('streams a CSV of the segment\'s members, masked by default, and audits it', function () {
    $segment = Segment::factory()->create();
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567', 'display_name' => 'Ann']);
    DB::table('segment_members')->insert(['segment_id' => $segment->id, 'customer_id' => $customer->id, 'added_at' => now()]);
    $user = Fx::userWith('customers.export');

    $response = $this->actingAs($user)->get("/segments/{$segment->id}/export");

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $body = $response->streamedContent();
    expect($body)->toContain('Ann')->toContain('********4567')->not->toContain('989121234567');

    $audit = DB::table('audit_logs')->where('action', 'segment.exported')->first();
    expect($audit)->not->toBeNull()->and((int) $audit->auditable_id)->toBe($segment->id);
});

it('reveals the full phone only with customers.view_full_phone on top of customers.export', function () {
    $segment = Segment::factory()->create();
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567']);
    DB::table('segment_members')->insert(['segment_id' => $segment->id, 'customer_id' => $customer->id, 'added_at' => now()]);
    $user = Fx::userWith('customers.export', 'customers.view_full_phone');

    $response = $this->actingAs($user)->get("/segments/{$segment->id}/export");

    expect($response->streamedContent())->toContain('989121234567');
});

it('answers 404 for a missing segment and a non-number', function () {
    $user = Fx::userWith('customers.export');

    $this->actingAs($user)->get('/segments/987654321/export')->assertNotFound();
    $this->actingAs($user)->get('/segments/abc/export')->assertNotFound();
});
