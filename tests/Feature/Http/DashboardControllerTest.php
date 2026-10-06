<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P6-06 — GET /dashboard: PRD §18's Dashboard, behind auth + dashboard.view. Replaces the starter-kit
| placeholder that used to live at this same route name.
*/

it('refuses a guest', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

it('refuses a signed-in user without dashboard.view', function () {
    $this->actingAs(Fx::userWith('customers.view'))->get('/dashboard')->assertForbidden();
});

it('renders the page for a holder of dashboard.view, with the composed dashboard data', function () {
    DB::table('daily_metrics')->insert([
        'date' => now()->toDateString(), 'jalali_date' => '1403-01-01',
        'orders_count' => 3, 'revenue' => 300_000, 'refunds' => 0, 'net_revenue' => 300_000, 'product_revenue' => 300_000, 'shipping_revenue' => 0, 'aov' => 100_000,
        'customers_total' => 3, 'customers_new' => 1, 'customers_repeat' => 2, 'revenue_new' => 100_000, 'revenue_repeat' => 200_000,
    ]);

    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('data.current.orders_count', 3)
            ->where('data.current.net_revenue', 300_000)
            ->has('data.rfm_distribution')
            ->has('data.churn_distribution')
            ->has('data.cohort_matrix')
            ->has('data.top_affinity')
        );
});

it('defaults to the last 30 days when no period is given', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', null)
            ->where('filters.to', null)
        );
});

it('accepts an explicit Jalali period and echoes it back', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/01/01&to=1403/01/10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '1403/01/01')
            ->where('filters.to', '1403/01/10')
        );
});

it('rejects a period with the end before the start', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/02/01&to=1403/01/01')
        ->assertInvalid('to');
});

it('rejects an invalid Jalali date rather than silently ignoring it', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=not-a-date&to=1403/01/10')
        ->assertInvalid('from');
});

it('requires from and to together', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/01/01')
        ->assertInvalid('to');
});

/*
| P6-14 phase 2: the Jalali range picker's backend validation (JalaliDayRule/JalaliDateRangeValidation) —
| every case named explicitly in the task, not guessed. The 422/Persian-message requirement is checked
| once here, in detail; the other two requests (customers, orders) reuse the exact same rule objects, so
| their own test files only need to prove they ARE wired, not re-prove the Jalali boundary logic itself.
*/
it('accepts the leap boundary 1403/12/30 (1403 is a leap Jalali year)', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/12/20&to=1403/12/30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.to', '1403/12/30'));
});

it('rejects 1404/12/30 (1404 is not a leap Jalali year, Esfand has 29 days)', function () {
    $response = $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1404/12/20&to=1404/12/30')
        ->assertInvalid('to');

    expect(session('errors')->get('to')[0])->toContain('تاریخ شمسی معتبر');
});

it('rejects month 13', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/13/01&to=1403/13/10')
        ->assertInvalid('from');
});

it('accepts 1405/01/01 as an ordinary valid day', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1404/12/20&to=1405/01/01')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.to', '1405/01/01'));
});

it('accepts مهر ۱ (1403/07/01) as an ordinary valid day', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/07/01&to=1403/07/10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.from', '1403/07/01'));
});

it('round-trips the query string exactly as typed', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/05/05&to=1403/05/15')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '1403/05/05')
            ->where('filters.to', '1403/05/15'));
});

it('every rejection is a 422 with a Persian message, never a bare validation key', function () {
    $response = $this->actingAs(Fx::userWith('dashboard.view'))
        ->withHeaders(['Accept' => 'application/json'])
        ->getJson('/dashboard?from=1404/12/30&to=1404/12/30');

    $response->assertStatus(422);
    $message = $response->json('errors.from.0');
    expect($message)->toBeString()->and(preg_match('/\p{Arabic}/u', $message))->toBe(1);
});

/*
| P6-11: the header ("از X تا Y") and the compare card's text both read period.*_jalali — never the plain
| Gregorian from/to also present in props for other consumers (the drill-down query params). This asserts
| the Jalali fields exist and are shaped like a Jalali date (a 13xx/14xx year), not that the plain
| Gregorian fields are absent — they still have a legitimate, non-displayed use (DrillDialog's params).
*/
it('gives the current AND previous period both a Jalali date, so nothing on the page ever needs to format one itself', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/dashboard?from=1403/01/01&to=1403/01/10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('data.period.from_jalali', '1403/01/01')
            ->where('data.period.to_jalali', '1403/01/10')
            ->has('data.period.previous_from_jalali')
            ->has('data.period.previous_to_jalali')
            ->where('data.period.previous_from_jalali', fn (string $v) => (bool) preg_match('/^1[34]\d{2}\/\d{2}\/\d{2}$/', $v))
            ->where('data.period.previous_to_jalali', fn (string $v) => (bool) preg_match('/^1[34]\d{2}\/\d{2}\/\d{2}$/', $v))
        );
});
