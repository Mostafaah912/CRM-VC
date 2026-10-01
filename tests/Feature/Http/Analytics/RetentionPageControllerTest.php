<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SystemPageFixtures as Fx;

/*
| P6-08 — GET /analytics/retention: the standalone Retention page, behind auth + analytics.view.
| One Service call (RetentionService::summary(), P6-08's thin composition of P6-04's three already-tested
| methods) -> one Inertia response.
*/

it('refuses a guest', function () {
    $this->get('/analytics/retention')->assertRedirect('/login');
});

it('refuses a signed-in user without analytics.view', function () {
    $this->actingAs(Fx::userWith('dashboard.view'))->get('/analytics/retention')->assertForbidden();
});

it('renders the page for a holder of analytics.view, with the composed summary', function () {
    $this->actingAs(Fx::userWith('analytics.view'))->get('/analytics/retention')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/retention')
            ->has('data.repeat_purchase_rate')
            ->has('data.returning_revenue_share')
            ->has('data.retention', 3)
        );
});
