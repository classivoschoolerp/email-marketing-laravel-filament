<?php

declare(strict_types=1);

use App\Enums\LoginResult;
use App\Filament\Customer\Pages\Security;
use App\Models\Customer;
use App\Models\CustomerRecoveryCode;
use App\Models\LoginLog;
use App\Services\Auth\TwoFactorService;
use App\Services\Security\SessionManager;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $this->customer = Customer::factory()->create(['password' => 'Secret123']);

    $this->actingAs($this->customer, 'customer');
});

function fakeSessionRow(int $customerId, string $id = 'sess-1', string $ip = '10.0.0.1'): void
{
    $key = app(SessionManager::class)->guardSessionKey();

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => null,
        'ip_address' => $ip,
        'user_agent' => 'Mozilla/5.0 (Testing)',
        'payload' => base64_encode(serialize([$key => $customerId, '_token' => 'x'])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

// F-2.15.2-001 — enable 2FA with QR + 10 hashed recovery codes
it('enables two-factor auth with a QR code and ten hashed recovery codes', function (): void {
    $component = Livewire::test(Security::class)->call('startTwoFactorSetup');

    $secret = $component->get('pendingSecret');

    expect($secret)->not->toBeNull()
        ->and($component->instance()->getQrCodeSvg())->toContain('<svg');

    $code = app(Google2FA::class)->getCurrentOtp((string) $secret);

    $component->set('confirmationCode', $code)->call('confirmTwoFactor');

    $customer = $this->customer->fresh();
    $plainCodes = $component->get('freshRecoveryCodes');

    expect($customer->twofa_enabled)->toBeTrue()
        ->and($customer->twofa_secret)->toBe($secret)
        ->and($plainCodes)->toHaveCount(10)
        ->and(CustomerRecoveryCode::query()->where('customer_id', $customer->id)->count())->toBe(10)
        // Hashed at rest — no plaintext code appears in the table.
        ->and(
            CustomerRecoveryCode::query()
                ->where('customer_id', $customer->id)
                ->whereIn('code_hash', $plainCodes)
                ->exists()
        )->toBeFalse();
});

it('rejects a wrong confirmation code during 2FA setup', function (): void {
    Livewire::test(Security::class)
        ->call('startTwoFactorSetup')
        ->set('confirmationCode', '000000')
        ->call('confirmTwoFactor')
        ->assertHasErrors(['confirmationCode']);

    expect($this->customer->fresh()->twofa_enabled)->toBeFalse();
});

// F-2.15.2-001 — disable requires the current password
it('disables two-factor auth with password confirmation', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $twoFactor->enable($this->customer, $twoFactor->generateSecret());

    // Wrong password → refused.
    Livewire::test(Security::class)
        ->set('currentPassword', 'wrong')
        ->call('disableTwoFactor')
        ->assertHasErrors(['currentPassword']);

    expect($this->customer->fresh()->twofa_enabled)->toBeTrue();

    Livewire::test(Security::class)
        ->set('currentPassword', 'Secret123')
        ->call('disableTwoFactor');

    $customer = $this->customer->fresh();

    expect($customer->twofa_enabled)->toBeFalse()
        ->and($customer->twofa_secret)->toBeNull()
        ->and(CustomerRecoveryCode::query()->where('customer_id', $customer->id)->count())->toBe(0);
});

it('regenerates recovery codes and invalidates the old ones', function (): void {
    $twoFactor = app(TwoFactorService::class);
    $oldCodes = $twoFactor->enable($this->customer, $twoFactor->generateSecret());

    $component = Livewire::test(Security::class)
        ->set('currentPassword', 'Secret123')
        ->call('regenerateRecoveryCodes');

    $newCodes = $component->get('freshRecoveryCodes');

    expect($newCodes)->toHaveCount(10)
        ->and($newCodes)->not->toBe($oldCodes)
        ->and($twoFactor->verifyRecoveryCode($this->customer, $oldCodes[0]))->toBeFalse()
        ->and($twoFactor->verifyRecoveryCode($this->customer, $newCodes[0]))->toBeTrue();
});

// F-2.15.2-002 — active sessions list + revoke
it('lists only the customer own sessions and can revoke them', function (): void {
    $other = Customer::factory()->create();

    fakeSessionRow($this->customer->id, 'sess-mine', '10.0.0.1');
    fakeSessionRow($other->id, 'sess-theirs', '10.0.0.2');

    $manager = app(SessionManager::class);

    $sessions = $manager->forCustomer($this->customer);

    expect($sessions)->toHaveCount(1)
        ->and($sessions[0]['id'])->toBe('sess-mine')
        ->and($sessions[0]['ip'])->toBe('10.0.0.1')
        ->and($sessions[0]['user_agent'])->toContain('Testing');

    // Cannot revoke someone else's session.
    expect($manager->revoke($this->customer, 'sess-theirs'))->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'sess-theirs')->exists())->toBeTrue();

    // Revoking an owned session deletes it.
    Livewire::test(Security::class)->call('revokeSession', 'sess-mine');

    expect(DB::table('sessions')->where('id', 'sess-mine')->exists())->toBeFalse();
});

// F-2.15.2-003 — login history table shows only the customer's rows
it('shows the login history for the current customer only', function (): void {
    $other = Customer::factory()->create();

    $mine = LoginLog::record('customer', $this->customer, $this->customer->email, LoginResult::Success);
    $failed = LoginLog::record('customer', $this->customer, $this->customer->email, LoginResult::Failed);
    $theirs = LoginLog::record('customer', $other, $other->email, LoginResult::Success);

    Livewire::test(Security::class)
        ->assertCanSeeTableRecords([$mine, $failed])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('renders the security page inside the panel', function (): void {
    $this->get('/customer/security')->assertOk();
});
