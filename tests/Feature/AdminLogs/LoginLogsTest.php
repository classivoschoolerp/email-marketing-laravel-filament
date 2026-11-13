<?php

declare(strict_types=1);

use App\Enums\LoginResult;
use App\Filament\Admin\Resources\LoginLogResource;
use App\Filament\Admin\Resources\LoginLogResource\Pages\ListLoginLogs;
use App\Filament\Admin\Resources\LoginLogResource\Widgets\CurrentLockoutsWidget;
use App\Models\Customer;
use App\Models\LoginLog;
use App\Models\User;
use App\Services\Auth\LoginThrottle;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

// §3.27 F-3.27-005/006 — global login logs (user + customer guards) with
// failed-attempts tab and the live brute-force lockout view.

beforeEach(function (): void {
    $this->user = User::factory()->create(['email' => 'admin@fixture.test']);
    $this->customer = Customer::factory()->create(['email' => 'cust@fixture.test']);

    $this->adminOk = LoginLog::record('admin', $this->user, $this->user->email, LoginResult::Success);
    $this->customerOk = LoginLog::record('customer', $this->customer, $this->customer->email, LoginResult::Success);
    $this->guestFail = LoginLog::record('customer', null, 'guest@fixture.test', LoginResult::Failed);
    $this->lockout = LoginLog::record('customer', null, 'guest@fixture.test', LoginResult::Lockout);
});

// F-3.27-005 — user/customer, ip, UA, result.
it('lists login logs for both guards with guard and result filters', function (): void {
    AdminTestHelpers::actingAdmin(['customers.view']);

    $this->get(LoginLogResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListLoginLogs::class)
        ->assertCanSeeTableRecords([$this->adminOk, $this->customerOk, $this->guestFail, $this->lockout])
        ->filterTable('guard', 'admin')
        ->assertCanSeeTableRecords([$this->adminOk])
        ->assertCanNotSeeTableRecords([$this->customerOk])
        ->resetTableFilters()
        ->filterTable('result', LoginResult::Failed->value)
        ->assertCanSeeTableRecords([$this->guestFail])
        ->assertCanNotSeeTableRecords([$this->adminOk, $this->lockout]);
});

// F-3.27-006 — guest failed attempts tab.
it('filters failed attempts through the tab', function (): void {
    AdminTestHelpers::actingAdmin(['customers.view']);

    Livewire::test(ListLoginLogs::class)
        ->set('activeTab', 'failed')
        ->assertCanSeeTableRecords([$this->guestFail, $this->lockout])
        ->assertCanNotSeeTableRecords([$this->adminOk, $this->customerOk])
        ->set('activeTab', 'success')
        ->assertCanSeeTableRecords([$this->adminOk, $this->customerOk])
        ->assertCanNotSeeTableRecords([$this->guestFail]);
});

// F-3.27-006 — live lockouts from the cache-backed RateLimiter (D-04):
// lockouts are temporary auto IP bans; the widget shows the current ones.
it('surfaces currently locked-out ips from the rate limiter', function (): void {
    config()->set('cache.default', 'array');

    $throttle = app(LoginThrottle::class);
    $ip = '203.0.113.9';

    // Log failures so the widget has a candidate IP, then trip the IP-wide
    // limiter past its threshold (default 20).
    LoginLog::record('customer', null, 'brute@fixture.test', LoginResult::Failed, request()->duplicate());

    LoginLog::query()->latest('id')->first()->forceFill(['ip' => $ip])->save();

    $widget = new CurrentLockoutsWidget;

    expect($throttle->ipLockedOut($ip))->toBeFalse()
        ->and($widget->lockouts())->toBe([]);

    for ($i = 0; $i < 21; $i++) {
        $throttle->hit('brute@fixture.test', $ip);
    }

    expect($throttle->ipLockedOut($ip))->toBeTrue();

    $lockouts = $widget->lockouts();

    expect($lockouts)->toHaveCount(1)
        ->and($lockouts[0]['ip'])->toBe($ip)
        ->and($lockouts[0]['attempts'])->toBe(21)
        ->and($lockouts[0]['available_in'])->toBeGreaterThan(0);
});

// Policy denial mirrors LoginLogPolicy (customers.view / users.view).
it('denies login logs to unpermitted admins', function (): void {
    AdminTestHelpers::actingAdmin(); // no permission

    $this->get(LoginLogResource::getUrl('index', panel: 'admin'))->assertForbidden();
});

// A customer session never reaches the admin panel: unauthenticated on the
// admin guard, the request bounces to the admin login.
it('denies login logs to customer-guard users', function (): void {
    $this->actingAs(Customer::factory()->create(), 'customer');
    $this->get(LoginLogResource::getUrl('index', panel: 'admin'))->assertRedirect();
});
