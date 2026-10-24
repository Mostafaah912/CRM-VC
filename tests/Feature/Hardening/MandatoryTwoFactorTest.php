<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use Tests\Feature\Hardening\HardeningTestHelpers;

beforeEach(function () {
    config(['security.two_factor.enforce' => true]);
});

it('sends an Owner or Manager without confirmed 2FA to the security page', function (RoleName $role) {
    $user = HardeningTestHelpers::userWithRole($role);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->actingAs($user)->getJson(route('dashboard'))->assertForbidden();
})->with([RoleName::Owner, RoleName::Manager]);

it('lets them reach the security page and sign out so they can enrol', function () {
    $user = HardeningTestHelpers::userWithRole(RoleName::Owner);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))->assertOk();

    $this->actingAs($user)->post(route('logout'))->assertRedirect(route('home'));
});

it('lets them submit the password confirmation that the security page itself demands', function () {
    $user = HardeningTestHelpers::userWithRole(RoleName::Owner);

    // Regression: password.confirm.store was not allow-listed, so the POST got a 403 and the form looked dead.
    $this->actingAs($user)->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'password'])
        ->assertStatus(201);
    $this->actingAs($user)->getJson(route('passkey.confirm-options'))->assertSuccessful();
});

it('lets an Owner with confirmed 2FA through', function () {
    $user = HardeningTestHelpers::userWithRole(RoleName::Owner);
    $user->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

    $this->actingAs($user)->get(route('appearance.edit'))->assertOk();
});

it('does not force 2FA on the other roles', function (RoleName $role) {
    $this->actingAs(HardeningTestHelpers::userWithRole($role))->get(route('appearance.edit'))->assertOk();
})->with([RoleName::Analyst, RoleName::Support, RoleName::Viewer]);

it('is switched off only by the explicit config flag', function () {
    config(['security.two_factor.enforce' => false]);

    $this->actingAs(HardeningTestHelpers::userWithRole(RoleName::Owner))->get(route('appearance.edit'))->assertOk();
});

it('enforces by default (config default is on)', function () {
    expect(config('security.two_factor.roles'))->toBe(['owner', 'manager'])
        ->and(file_get_contents(base_path('config/security.php')))->toContain("env('SECURITY_ENFORCE_2FA', true)");
});
