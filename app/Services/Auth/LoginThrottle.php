<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Settings\SettingsReader;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Failed-attempt throttling + IP lockout for customer authentication
 * (spec §1.3 F-1.3-004). Two counters: one per email+IP pair, one per IP
 * (catches attacks that rotate target accounts). Uses Laravel's RateLimiter
 * abstraction only (D-04 — Redis in prod, database/array locally).
 *
 * WP-22 (spec §3.25.15 F-3.25.15-001): the attempt limits and lockout
 * durations now come from the `security` settings group, with the original
 * config('emp.login.*') values as the fallback for installations whose
 * settings have not been migrated yet. The seeded settings defaults are
 * identical to those config values, so behaviour is unchanged until an
 * operator edits the Security settings page.
 */
class LoginThrottle
{
    public function tooManyAttempts(string $email, string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->emailKey($email, $ip), $this->maxAttempts())
            || RateLimiter::tooManyAttempts($this->ipKey($ip), $this->ipMaxAttempts());
    }

    public function hit(string $email, string $ip): void
    {
        RateLimiter::hit($this->emailKey($email, $ip), $this->lockoutSeconds());
        RateLimiter::hit($this->ipKey($ip), $this->ipLockoutSeconds());
    }

    /** Clears the per-account counter after a successful login. The IP-wide counter decays naturally. */
    public function clear(string $email, string $ip): void
    {
        RateLimiter::clear($this->emailKey($email, $ip));
    }

    public function availableIn(string $email, string $ip): int
    {
        return max(
            RateLimiter::tooManyAttempts($this->emailKey($email, $ip), $this->maxAttempts())
                ? RateLimiter::availableIn($this->emailKey($email, $ip))
                : 0,
            RateLimiter::tooManyAttempts($this->ipKey($ip), $this->ipMaxAttempts())
                ? RateLimiter::availableIn($this->ipKey($ip))
                : 0,
        );
    }

    /**
     * Whether an IP is currently locked out by the IP-wide counter (WP-17,
     * spec §3.27 F-3.27-006): the admin brute-force view derives candidate
     * IPs from login_logs failures and asks the live RateLimiter — lockouts
     * are cache-based by design (D-04), there is no persistent ban table.
     */
    public function ipLockedOut(string $ip): bool
    {
        return RateLimiter::tooManyAttempts($this->ipKey($ip), $this->ipMaxAttempts());
    }

    /** Current failed-attempt count on the IP-wide counter (WP-17). */
    public function ipAttempts(string $ip): int
    {
        return (int) RateLimiter::attempts($this->ipKey($ip));
    }

    /** Seconds until the IP-wide lockout lifts (0 when not locked out). */
    public function ipAvailableIn(string $ip): int
    {
        return $this->ipLockedOut($ip) ? (int) RateLimiter::availableIn($this->ipKey($ip)) : 0;
    }

    /** WP-22: §3.25.15 setting, config fallback. */
    private function maxAttempts(): int
    {
        return $this->setting(
            static fn (): int => SettingsReader::security()->loginMaxAttempts(),
            static fn (): int => (int) config('emp.login.max_attempts', 5),
        );
    }

    /** WP-22: §3.25.15 setting, config fallback. */
    private function ipMaxAttempts(): int
    {
        return $this->setting(
            static fn (): int => SettingsReader::security()->loginIpMaxAttempts(),
            static fn (): int => (int) config('emp.login.ip_max_attempts', 20),
        );
    }

    /** Per-account lockout duration in seconds (WP-22 §3.25.15). */
    private function lockoutSeconds(): int
    {
        return $this->setting(
            static fn (): int => SettingsReader::security()->loginLockoutSeconds(),
            static fn (): int => (int) config('emp.login.decay_seconds', 60),
        );
    }

    /** IP-wide lockout duration in seconds (WP-22 §3.25.15). */
    private function ipLockoutSeconds(): int
    {
        return $this->setting(
            static fn (): int => SettingsReader::security()->loginIpLockoutSeconds(),
            static fn (): int => (int) config('emp.login.ip_decay_seconds', 600),
        );
    }

    /**
     * Settings-first with a config fallback: a login must never 500 because
     * the settings table is missing (installer / first boot).
     *
     * @param  callable(): int  $fromSettings
     * @param  callable(): int  $fromConfig
     */
    private function setting(callable $fromSettings, callable $fromConfig): int
    {
        try {
            return $fromSettings();
        } catch (Throwable) {
            return $fromConfig();
        }
    }

    private function emailKey(string $email, string $ip): string
    {
        return 'emp-login:'.sha1(mb_strtolower($email).'|'.$ip);
    }

    private function ipKey(string $ip): string
    {
        return 'emp-login-ip:'.sha1($ip);
    }
}
