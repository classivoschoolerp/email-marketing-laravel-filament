<?php

declare(strict_types=1);

use App\Services\Auth\LoginThrottle;
use App\Settings\SecuritySettings;
use Illuminate\Support\Facades\RateLimiter;

/*
 * WP-22 — F-3.25.15-001: login attempt limits & lockout duration are read
 * from the security settings group, with the config values as the fallback.
 */

beforeEach(function (): void {
    $this->throttle = app(LoginThrottle::class);
    RateLimiter::clear('emp-login:'.sha1('victim@example.com|10.0.0.1'));
    RateLimiter::clear('emp-login-ip:'.sha1('10.0.0.1'));
});

it('locks an account out after the configured number of attempts', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->login_max_attempts = 2;
    $settings->save();

    expect($this->throttle->tooManyAttempts('victim@example.com', '10.0.0.1'))->toBeFalse();

    $this->throttle->hit('victim@example.com', '10.0.0.1');
    expect($this->throttle->tooManyAttempts('victim@example.com', '10.0.0.1'))->toBeFalse();

    $this->throttle->hit('victim@example.com', '10.0.0.1');
    expect($this->throttle->tooManyAttempts('victim@example.com', '10.0.0.1'))->toBeTrue();
});

it('applies the configured lockout duration to the account counter', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->login_max_attempts = 1;
    $settings->login_lockout_minutes = 30;
    $settings->save();

    $this->throttle->hit('victim@example.com', '10.0.0.1');

    expect($this->throttle->tooManyAttempts('victim@example.com', '10.0.0.1'))->toBeTrue()
        // 30 minutes, not the 60-second config default.
        ->and($this->throttle->availableIn('victim@example.com', '10.0.0.1'))
        ->toBeGreaterThan(1700)
        ->and($this->throttle->availableIn('victim@example.com', '10.0.0.1'))
        ->toBeLessThanOrEqual(1800);
});

it('locks an IP out across accounts using the configured IP limits', function (): void {
    $settings = app(SecuritySettings::class);
    $settings->login_max_attempts = 100;   // account counter must not interfere
    $settings->login_ip_max_attempts = 2;
    $settings->login_ip_lockout_minutes = 20;
    $settings->save();

    $this->throttle->hit('a@example.com', '10.0.0.1');
    $this->throttle->hit('b@example.com', '10.0.0.1');

    expect($this->throttle->ipLockedOut('10.0.0.1'))->toBeTrue()
        ->and($this->throttle->ipAttempts('10.0.0.1'))->toBe(2)
        ->and($this->throttle->ipAvailableIn('10.0.0.1'))->toBeGreaterThan(1100)
        ->and($this->throttle->ipAvailableIn('10.0.0.1'))->toBeLessThanOrEqual(1200);
});

// The seeded defaults reproduce the pre-WP-22 behaviour exactly.
it('behaves like the config defaults when the settings are untouched', function (): void {
    for ($i = 0; $i < 4; $i++) {
        $this->throttle->hit('victim@example.com', '10.0.0.1');
    }

    expect($this->throttle->tooManyAttempts('victim@example.com', '10.0.0.1'))->toBeFalse();

    $this->throttle->hit('victim@example.com', '10.0.0.1');

    expect($this->throttle->tooManyAttempts('victim@example.com', '10.0.0.1'))->toBeTrue()
        ->and($this->throttle->availableIn('victim@example.com', '10.0.0.1'))->toBeLessThanOrEqual(60);
});
