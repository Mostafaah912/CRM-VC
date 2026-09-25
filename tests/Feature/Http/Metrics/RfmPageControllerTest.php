<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P4-08 part B — GET /metrics/rfm: PRD §12's RFM distribution page, behind auth + metrics.view.
| Read-only; carries customer_id/total_revenue/rfm_score for top champions — never a name or phone.
*/

it('refuses a guest', function () {
    $this->get('/metrics/rfm')->assertRedirect('/login');
});

it('refuses a signed-in user without metrics.view', function () {
    $this->actingAs(Fx::userWith('customers.view'))->get('/metrics/rfm')->assertForbidden();
});

it('renders the page for a holder of metrics.view, with the segment/score/champion data', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert([
        'customer_id' => $customer->id, 'total_orders' => 5, 'total_revenue' => 5_000_000,
        'r_score' => 5, 'f_score' => 5, 'm_score' => 5, 'rfm_score' => '555', 'rfm_segment' => 'champion',
    ]);

    $this->actingAs(Fx::userWith('metrics.view'))->get('/metrics/rfm')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('metrics/rfm')
            ->where('data.segments.champion', 1)
            ->where('data.top_champions.0.customer_id', $customer->id)
            ->where('data.top_champions.0.total_revenue', 5_000_000)
            ->missing('data.top_champions.0.display_name')
        );
});

it('never leaks a name or phone anywhere in the response', function () {
    $customer = Customer::factory()->create(['display_name' => 'ZZ-Rfm-Leak-Test', 'phone_normalized' => '989121230099']);
    DB::table('customer_metrics')->insert([
        'customer_id' => $customer->id, 'total_orders' => 1, 'total_revenue' => 1_000_000, 'rfm_segment' => 'champion',
    ]);

    $response = $this->actingAs(Fx::userWith('metrics.view'))->get('/metrics/rfm')->assertOk();

    expect($response->getContent())
        ->not->toContain('ZZ-Rfm-Leak-Test')
        ->not->toContain('989121230099');
});
