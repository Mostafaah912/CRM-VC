<?php

declare(strict_types=1);

use App\Modules\Analytics\Services\AffinityService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P6-08 — GET /analytics/affinity: the standalone Affinity page, behind auth + analytics.view. One
| Service call (AffinityService::topAll(), P6-08's thin composition over all 4 levels) -> one Inertia
| response. PRD's own backlog title is "Affinity (4 levels)", so the page shows all four.
*/

it('refuses a guest', function () {
    $this->get('/analytics/affinity')->assertRedirect('/login');
});

it('refuses a signed-in user without analytics.view', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/analytics/affinity')->assertForbidden();
});

it('renders the page for a holder of analytics.view, with all 4 levels', function () {
    $this->actingAs(Fx::userWith('analytics.view'))->get('/analytics/affinity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('analytics/affinity')
            ->has('data.category')
            ->has('data.product')
            ->has('data.variation')
            ->has('data.basket')
        );
});

it('includes a real stored pair for a holder of analytics.view', function () {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    for ($i = 0; $i < 10; $i++) {
        $customerId = Customer::factory()->create()->id;
        DB::table('customer_product_purchases')->insert(['customer_id' => $customerId, 'product_id' => $a->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
        DB::table('customer_product_purchases')->insert(['customer_id' => $customerId, 'product_id' => $b->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
    }
    for ($i = 0; $i < 10; $i++) {
        DB::table('customer_product_purchases')->insert(['customer_id' => Customer::factory()->create()->id, 'product_id' => Product::factory()->create()->id, 'orders_count' => 1, 'items_count' => 1, 'revenue' => 1]);
    }
    app(AffinityService::class)->rebuild();

    $this->actingAs(Fx::userWith('analytics.view'))->get('/analytics/affinity')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('data.product.0.co_customers', 10));
});
