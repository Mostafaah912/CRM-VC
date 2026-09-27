<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P6-08 — GET /analytics/cohort: the standalone Cohort matrix page, behind auth + analytics.view.
| One Service call (CohortSnapshotService::matrix(), already built and tested in P6-06) -> one Inertia
| response. No new query here.
*/

it('refuses a guest', function () {
    $this->get('/analytics/cohort')->assertRedirect('/login');
});

it('refuses a signed-in user without analytics.view', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/analytics/cohort')->assertForbidden();
});

it('renders the page for a holder of analytics.view, with the matrix from CohortSnapshotService', function () {
    DB::table('cohort_snapshots')->insert([
        'cohort_month' => '1403-01', 'period_number' => 0, 'cohort_size' => 5, 'active_customers' => 5,
        'retention_rate' => 1.0, 'orders_count' => 5, 'revenue' => 500_000, 'cumulative_revenue' => 500_000,
    ]);

    $this->actingAs(Fx::userWith('analytics.view'))->get('/analytics/cohort')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/cohort')
            ->where('data.0.cohort_month', '1403-01')
            ->where('data.0.cohort_size', 5)
        );
});

it('renders an empty matrix, not an error, when nothing has been computed yet', function () {
    $this->actingAs(Fx::userWith('analytics.view'))->get('/analytics/cohort')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('analytics/cohort')->where('data', []));
});
