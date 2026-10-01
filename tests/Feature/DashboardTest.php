<?php

use Tests\Support\SystemPageFixtures as Fx;

/*
| P6-06: the dashboard is now a real, permission-gated page (dashboard.view), not the starter-kit
| placeholder every authenticated user could see — see tests/Feature/Http/DashboardControllerTest.php
| for its full behavior.
*/

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('a holder of dashboard.view can visit the dashboard', function () {
    $this->actingAs(Fx::userWith('dashboard.view'));

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
