<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use Tests\Feature\Hardening\HardeningTestHelpers;

it('lets only the Owner into Horizon', function () {
    $this->actingAs(HardeningTestHelpers::userWithRole(RoleName::Owner))
        ->get('/horizon')
        ->assertSuccessful();
});

it('returns 403 to every other role and to guests', function (?RoleName $role) {
    $request = $role === null
        ? $this->get('/horizon')
        : $this->actingAs(HardeningTestHelpers::userWithRole($role))->get('/horizon');

    $request->assertForbidden();
})->with([
    'manager' => RoleName::Manager,
    'analyst' => RoleName::Analyst,
    'support' => RoleName::Support,
    'viewer' => RoleName::Viewer,
    'guest' => [null],
]);
