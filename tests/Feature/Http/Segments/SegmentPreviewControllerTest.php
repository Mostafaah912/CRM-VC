<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P5-05/06: POST /segments/preview — the Rule Builder's live count for a draft rule that may not be
| saved yet. Behind auth + (segments.create OR segments.edit). A refused/failed preview is always a
| clear Persian message with a 422, never a bare 500 (bootstrap/app.php's exception->render() mapping).
*/

it('answers a guest with a redirect to login', function () {
    $this->post('/segments/preview', ['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]])
        ->assertRedirect(route('login'));
});

it('forbids a user without segments.create or segments.edit', function () {
    $user = Fx::userWith('segments.view');

    $this->actingAs($user)
        ->postJson('/segments/preview', ['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 1]])
        ->assertForbidden();
});

it('allows a user who holds only segments.edit', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 5]);
    $user = Fx::userWith('segments.edit');

    $this->actingAs($user)
        ->postJson('/segments/preview', ['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 3]])
        ->assertOk()
        ->assertJson(['count' => 1]);
});

it('returns the matching customer count for a valid rule', function () {
    $customer = Customer::factory()->create();
    DB::table('customer_metrics')->insert(['customer_id' => $customer->id, 'total_orders' => 5]);
    $user = Fx::userWith('segments.create');

    $this->actingAs($user)
        ->postJson('/segments/preview', ['rule' => ['field' => 'total_orders', 'operator' => '>=', 'value' => 3]])
        ->assertOk()
        ->assertJson(['count' => 1]);
});

it('rejects a missing rule with a validation error, not a 500', function () {
    $user = Fx::userWith('segments.create');

    $this->actingAs($user)
        ->postJson('/segments/preview', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rule']);
});

it('rejects a field outside the whitelist with a Persian 422, not a 500', function () {
    $user = Fx::userWith('segments.create');

    $response = $this->actingAs($user)
        ->postJson('/segments/preview', ['rule' => ['field' => 'email', 'operator' => '=', 'value' => 'x']])
        ->assertStatus(422);

    expect($response->json('message'))->toMatch('/\p{Arabic}/u');
});

it('rejects a classic SQL injection payload used as a field name, with a Persian 422', function () {
    $user = Fx::userWith('segments.create');

    $response = $this->actingAs($user)
        ->postJson('/segments/preview', ['rule' => [
            'field' => "'; DROP TABLE customers; --",
            'operator' => '=',
            'value' => 1,
        ]])
        ->assertStatus(422);

    expect($response->json('message'))->toMatch('/\p{Arabic}/u');

    expect(Schema::hasTable('customers'))->toBeTrue();
});

it('rejects a classic SQL injection payload used as an operator, with a Persian 422', function () {
    $user = Fx::userWith('segments.create');

    $this->actingAs($user)
        ->postJson('/segments/preview', ['rule' => [
            'field' => 'total_orders',
            'operator' => '= 1; DROP TABLE customers; --',
            'value' => 1,
        ]])
        ->assertStatus(422);
});

it('returns a Persian 422, not a 500, when the preview genuinely times out', function () {
    DB::statement("
        INSERT INTO customers (phone_normalized, display_name, city, status, lifecycle_stage, metrics_dirty, needs_review, created_at, updated_at)
        SELECT '98900'||lpad(gs::text, 7, '0'), 'کاربر '||gs, 'شهر '||(gs % 50), 'active', 'prospect', true, false, now(), now()
        FROM generate_series(1, 20000) gs
    ");
    DB::statement('INSERT INTO customer_metrics (customer_id, total_orders, computed_at) SELECT id, 0, now() FROM customers');
    config(['segments.preview_timeout_ms' => 1]);
    $user = Fx::userWith('segments.create');

    $response = $this->actingAs($user)
        ->postJson('/segments/preview', ['rule' => ['field' => 'city', 'operator' => 'contains', 'value' => 'شهر']])
        ->assertStatus(422);

    expect($response->json('message'))->toMatch('/\p{Arabic}/u');

    config(['segments.preview_timeout_ms' => 5000]);
});
