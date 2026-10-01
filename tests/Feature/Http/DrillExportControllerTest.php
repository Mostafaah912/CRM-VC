<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P6-07 — GET /internal/drill/{widget}/export: the audited CSV export, mirroring
| SegmentExportControllerTest exactly. Gated on BOTH dashboard.view (it is still a drill of the
| Dashboard) and customers.export (the PII release) at the route level, in addition to the Service's
| own customers.export check.
*/

it('answers a guest with a redirect to login', function () {
    $this->get('/internal/drill/orders/export')->assertRedirect('/login');
});

it('forbids a user with dashboard.view but not customers.export', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/internal/drill/orders/export')->assertForbidden();
});

it('forbids a user with customers.export but not dashboard.view', function () {
    $this->actingAs(Fx::userWith('customers.export'))->get('/internal/drill/orders/export')->assertForbidden();
});

it('streams a CSV of the drilled orders, masked by default, and audits it', function () {
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567', 'display_name' => 'Ann']);
    Order::factory()->for($customer)->create(['is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => now(), 'total' => 250_000]);
    $user = Fx::userWith('dashboard.view', 'customers.export');

    $response = $this->actingAs($user)->get('/internal/drill/orders/export');

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $body = $response->streamedContent();
    expect($body)->toContain('Ann')->toContain('4567')->not->toContain('989121234567');

    $audit = DB::table('audit_logs')->where('action', 'dashboard.drill_exported')->first();
    expect($audit)->not->toBeNull()->and(json_decode((string) $audit->after, true))->toMatchArray(['widget' => 'orders']);
});

it('reveals the full phone only with customers.view_full_phone on top of customers.export', function () {
    $customer = Customer::factory()->create(['phone_normalized' => '989121234567']);
    Order::factory()->for($customer)->create(['is_realized' => true, 'is_fully_refunded' => false, 'ordered_at' => now(), 'total' => 250_000]);
    $user = Fx::userWith('dashboard.view', 'customers.export', 'customers.view_full_phone');

    $response = $this->actingAs($user)->get('/internal/drill/orders/export');

    expect($response->streamedContent())->toContain('989121234567');
});

it('answers 404 for a widget it does not know', function () {
    $user = Fx::userWith('dashboard.view', 'customers.export');

    $this->actingAs($user)->get('/internal/drill/not-a-widget/export')->assertNotFound();
});
