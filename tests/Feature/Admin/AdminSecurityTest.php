<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Security;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Services\Auth\TwoFactorService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

function wp03_security_admin(): User
{
    $user = User::factory()->create(['password' => 'Current#Pass1']);

    test()->actingAs($user, 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $user;
}

// D-35 — admin Security page mirrors the customer 2FA enrolment
it('renders the admin security page', function (): void {
    wp03_security_admin();

    $this->get(Security::getUrl(panel: 'admin'))->assertOk();
});

it('enables 2fa for an admin with recovery codes hashed at rest', function (): void {
    $admin = wp03_security_admin();

    $component = Livewire::test(Security::class)
        ->call('startTwoFactorSetup');

    $secret = $component->get('pendingSecret');
    expect($secret)->not->toBeNull();

    $component
        ->set('confirmationCode', app(Google2FA::class)->getCurrentOtp($secret))
        ->call('confirmTwoFactor')
        ->assertHasNoErrors();

    $admin->refresh();

    expect($admin->twofa_enabled)->toBeTrue()
        ->and($admin->twofa_secret)->toBe($secret)
        ->and(UserRecoveryCode::query()->where('user_id', $admin->id)->count())
        ->toBeGreaterThan(0);

    $codes = $component->get('freshRecoveryCodes');

    expect($codes)->not->toBeEmpty()
        // hashed at rest — plaintext never stored
        ->and(UserRecoveryCode::query()->where('code_hash', $codes[0])->exists())->toBeFalse()
        // recovery code verifies + single use
        ->and(app(TwoFactorService::class)->verifyRecoveryCode($admin, $codes[0]))->toBeTrue()
        ->and(app(TwoFactorService::class)->verifyRecoveryCode($admin, $codes[0]))->toBeFalse();
});

it('disables 2fa with the current password', function (): void {
    $admin = wp03_security_admin();

    app(TwoFactorService::class)->enable($admin, app(TwoFactorService::class)->generateSecret());

    Livewire::test(Security::class)
        ->set('currentPassword', 'Current#Pass1')
        ->call('disableTwoFactor')
        ->assertHasNoErrors();

    $admin->refresh();

    expect($admin->twofa_enabled)->toBeFalse()
        ->and($admin->twofa_secret)->toBeNull()
        ->and(UserRecoveryCode::query()->where('user_id', $admin->id)->count())->toBe(0);
});

// F-3.2-004 — password change clears the force-password-reset flag
it('changes the password and clears the force reset flag', function (): void {
    $admin = wp03_security_admin();
    $admin->forceFill(['force_password_reset' => true])->save();

    Livewire::test(Security::class)
        ->set('passwordCurrent', 'Current#Pass1')
        ->set('passwordNew', 'BrandNew#Pass2')
        ->set('passwordNewConfirmation', 'BrandNew#Pass2')
        ->call('changePassword')
        ->assertHasNoErrors();

    $admin->refresh();

    expect($admin->force_password_reset)->toBeFalse()
        ->and(\Illuminate\Support\Facades\Hash::check('BrandNew#Pass2', $admin->password))->toBeTrue();
});

it('rejects a wrong current password on password change', function (): void {
    $admin = wp03_security_admin();

    Livewire::test(Security::class)
        ->set('passwordCurrent', 'wrong-password')
        ->set('passwordNew', 'BrandNew#Pass2')
        ->set('passwordNewConfirmation', 'BrandNew#Pass2')
        ->call('changePassword')
        ->assertHasErrors(['passwordCurrent']);

    expect(\Illuminate\Support\Facades\Hash::check('Current#Pass1', $admin->fresh()->password))->toBeTrue();
});

// F-3.2-004 — flagged admins are redirected to the Security page
it('redirects a flagged admin to the security page until the password is changed', function (): void {
    $admin = User::factory()->create(['password' => 'Current#Pass1']);
    $admin->forceFill(['force_password_reset' => true])->save();

    $this->actingAs($admin, 'admin');

    $this->get('/admin')
        ->assertRedirect(Security::getUrl(panel: 'admin'));

    // The Security page itself stays reachable (no redirect loop).
    $this->get(Security::getUrl(panel: 'admin'))->assertOk();

    $admin->forceFill(['force_password_reset' => false])->save();

    $this->get('/admin')->assertOk();
});
