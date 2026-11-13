<?php

declare(strict_types=1);

use App\Enums\LoginResult;
use App\Filament\Customer\Auth\Login;
use App\Filament\Customer\Pages\Security;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Services\Security\AccountIpAllowlist;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * §2.15.2 F-2.15.2-004 — per-account IP allowlist (WP-46).
 *
 * Enforced in two places on purpose: the login form (so a disallowed address
 * never even reaches the 2FA challenge) and the customer panel's auth
 * middleware (so social login, one-time tokens, remembered cookies and a
 * session that MOVED to a bad address are all covered).
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('customer'));

    $this->customer = Customer::factory()->create([
        'email' => 'anna@example.test',
        'password' => 'Secret123',
    ]);

    $this->allowlist = app(AccountIpAllowlist::class);
});

it('treats an empty list as unrestricted', function (): void {
    expect($this->allowlist->restricted($this->customer))->toBeFalse()
        ->and($this->allowlist->allows($this->customer, '203.0.113.9'))->toBeTrue()
        ->and($this->allowlist->allows($this->customer, null))->toBeTrue();

    $this->customer->forceFill(['ip_allowlist' => []])->save();

    expect($this->allowlist->allows($this->customer->fresh(), '198.51.100.1'))->toBeTrue();
});

it('matches single addresses and CIDR ranges, IPv4 and IPv6', function (): void {
    $this->customer->forceFill([
        'ip_allowlist' => ['203.0.113.4', '198.51.100.0/24', '2001:db8::/32'],
    ])->save();

    $customer = $this->customer->fresh();

    expect($this->allowlist->allows($customer, '203.0.113.4'))->toBeTrue()
        ->and($this->allowlist->allows($customer, '203.0.113.5'))->toBeFalse()
        ->and($this->allowlist->allows($customer, '198.51.100.77'))->toBeTrue()
        ->and($this->allowlist->allows($customer, '198.51.101.77'))->toBeFalse()
        ->and($this->allowlist->allows($customer, '2001:db8:1234::1'))->toBeTrue()
        ->and($this->allowlist->allows($customer, '2001:dba::1'))->toBeFalse();
});

it('refuses a request whose address is unknown when a list is configured', function (): void {
    $this->customer->forceFill(['ip_allowlist' => ['203.0.113.4']])->save();

    // An origin we cannot determine is exactly what an allowlist exists to
    // refuse — failing open here would make the control decorative.
    expect($this->allowlist->allows($this->customer->fresh(), null))->toBeFalse()
        ->and($this->allowlist->allows($this->customer->fresh(), ''))->toBeFalse();
});

it('blocks the login form from a disallowed address and records it', function (): void {
    $this->customer->forceFill(['ip_allowlist' => ['203.0.113.0/24']])->save();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'anna@example.test', 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect(auth('customer')->check())->toBeFalse();

    $log = LoginLog::query()->latest('id')->first();

    expect($log?->result)->toBe(LoginResult::Blocked)
        ->and($log?->model_id)->toBe($this->customer->id);
});

it('lets the login form through from an allowed address', function (): void {
    // The test client reports 127.0.0.1; allow exactly that.
    $this->customer->forceFill(['ip_allowlist' => ['127.0.0.1']])->save();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'anna@example.test', 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('customer')->check())->toBeTrue();
});

it('cuts off an authenticated panel session that no longer matches', function (): void {
    $this->actingAs($this->customer, 'customer');

    // Still allowed…
    $this->customer->forceFill(['ip_allowlist' => ['127.0.0.1']])->save();
    $this->get('/customer/security')->assertOk();

    // …now the account only permits a network this request is not on.
    $this->customer->forceFill(['ip_allowlist' => ['203.0.113.0/24']])->save();

    $this->get('/customer/security')
        ->assertRedirect('/customer/login');

    expect(auth('customer')->check())->toBeFalse();
});

it('never applies a tenant allowlist to the admin panel', function (): void {
    $admin = App\Models\User::factory()->create();

    $this->customer->forceFill(['ip_allowlist' => ['203.0.113.0/24']])->save();

    // The middleware is registered on the customer panel only, so an
    // operator (including one impersonating) is untouched by a tenant rule.
    $this->actingAs($admin, 'admin');

    $this->get('/admin')->assertSuccessful();
});

it('validates entries and refuses a list that would lock the author out', function (): void {
    $service = app(AccountIpAllowlist::class);

    expect($service->normalize([' 203.0.113.4 ', '203.0.113.4', '']))
        ->toBe(['203.0.113.4']);

    expect(fn () => $service->normalize(['not-an-ip']))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->normalize(['203.0.113.0/33']))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->normalize(array_fill(0, AccountIpAllowlist::MAX_ENTRIES + 1, '203.0.113.4')))
        // Duplicates collapse, so build genuinely distinct entries instead.
        ->not->toThrow(ValidationException::class);

    $tooMany = [];
    for ($i = 0; $i <= AccountIpAllowlist::MAX_ENTRIES; $i++) {
        $tooMany[] = '203.0.113.'.$i;
    }
    expect(fn () => $service->normalize($tooMany))->toThrow(ValidationException::class);

    // Lockout guard.
    expect(fn () => $service->assertWouldNotLockOut(['203.0.113.4'], '198.51.100.1'))
        ->toThrow(ValidationException::class);
    expect(fn () => $service->assertWouldNotLockOut(['203.0.113.0/24'], '203.0.113.9'))
        ->not->toThrow(ValidationException::class);
    // An empty list is never a lockout — it is switching the feature off.
    expect(fn () => $service->assertWouldNotLockOut([], '198.51.100.1'))
        ->not->toThrow(ValidationException::class);
});

it('saves the allowlist from the security page behind the current password', function (): void {
    $this->actingAs($this->customer, 'customer');

    // Wrong password: nothing is written.
    Livewire::test(Security::class)
        ->set('ipAllowlist', ['127.0.0.1'])
        ->set('currentPassword', 'wrong')
        ->call('saveIpAllowlist')
        ->assertHasErrors('currentPassword');

    expect($this->customer->fresh()->ip_allowlist)->toBeNull();

    // Right password, and a list that includes this request's address.
    Livewire::test(Security::class)
        ->set('ipAllowlist', ['127.0.0.1', '203.0.113.0/24'])
        ->set('currentPassword', 'Secret123')
        ->call('saveIpAllowlist')
        ->assertHasNoErrors();

    expect($this->customer->fresh()->ip_allowlist)->toBe(['127.0.0.1', '203.0.113.0/24']);

    // A list that excludes this address is refused rather than saved.
    Livewire::test(Security::class)
        ->set('ipAllowlist', ['203.0.113.0/24'])
        ->set('currentPassword', 'Secret123')
        ->call('saveIpAllowlist')
        ->assertHasErrors('ipAllowlist');

    expect($this->customer->fresh()->ip_allowlist)->toBe(['127.0.0.1', '203.0.113.0/24']);

    // Clearing it switches the feature off.
    Livewire::test(Security::class)
        ->set('ipAllowlist', [])
        ->set('currentPassword', 'Secret123')
        ->call('saveIpAllowlist')
        ->assertHasNoErrors();

    expect($this->customer->fresh()->ip_allowlist)->toBeNull();
});

it('keeps one account\'s allowlist out of another account\'s way', function (): void {
    $other = Customer::factory()->create(['email' => 'bob@example.test', 'password' => 'Secret123']);

    $this->customer->forceFill(['ip_allowlist' => ['203.0.113.0/24']])->save();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'bob@example.test', 'password' => 'Secret123'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth('customer')->id())->toBe($other->id);
});
