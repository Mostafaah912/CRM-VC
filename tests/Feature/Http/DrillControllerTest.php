<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P6-07 — GET /internal/drill/{widget}: PRD Sec.18's boxed uniform drill-down, behind auth + dashboard.view
| (the same gate as the Dashboard page itself, since drilling is part of viewing it).
*/

it('refuses a guest', function () {
    $this->get('/internal/drill/orders')->assertRedirect('/login');
});

it('refuses a signed-in user without dashboard.view', function () {
    $this->actingAs(Fx::userWith('customers.view'))->get('/internal/drill/orders')->assertForbidden();
});

it('answers 404 for a widget it does not know', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/not-a-widget')->assertNotFound();
});

it('returns the orders behind the default period, PII-minimal, for a holder of dashboard.view', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create(['is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => now(), 'total' => 250_000]);

    $response = $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/orders');

    $response->assertOk()->assertJsonStructure(['columns', 'rows', 'truncated']);
    expect($response->json('columns'))->not->toContain('display_name')
        ->and($response->json('rows'))->toHaveCount(1);
});

it('rejects an rfm_segment drill with no segment parameter', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/rfm_segment')->assertInvalid('segment');
});

it('filters an rfm_segment drill by the requested segment', function () {
    $champion = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $champion->id, 'rfm_segment' => 'champion']);
    $other = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $other->id, 'rfm_segment' => 'lost']);

    $response = $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/rfm_segment?segment=champion');

    $response->assertOk();
    expect($response->json('rows'))->toHaveCount(1)
        ->and($response->json('rows.0.customer_id'))->toBe($champion->id);
});

it('rejects an unknown segment value rather than silently returning nothing', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/rfm_segment?segment=not-a-segment')->assertInvalid('segment');
});

it('requires cohort_month and period_number for a cohort_period drill', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/cohort_period')->assertInvalid(['cohort_month', 'period_number']);
});

it('requires affinity_level, entity_a_id and entity_b_id for an affinity_pair drill', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/affinity_pair')->assertInvalid(['affinity_level', 'entity_a_id', 'entity_b_id']);
});

it('rejects the basket level for affinity_pair, since it is not implemented', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))
        ->get('/internal/drill/affinity_pair?affinity_level=basket&entity_a_id=1&entity_b_id=2')
        ->assertInvalid('affinity_level');
});
