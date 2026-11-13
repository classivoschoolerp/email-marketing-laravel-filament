<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outcome recorded per login attempt (spec §1.3 — login_logs).
 */
enum LoginResult: string
{
    case Success = 'success';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Lockout = 'lockout';
    case TwoFactorFailed = 'twofa_failed';

    public function label(): string
    {
        return match ($this) {
            self::Success => __('auth-module.login_log.result.success'),
            self::Failed => __('auth-module.login_log.result.failed'),
            self::Blocked => __('auth-module.login_log.result.blocked'),
            self::Lockout => __('auth-module.login_log.result.lockout'),
            self::TwoFactorFailed => __('auth-module.login_log.result.twofa_failed'),
        };
    }
}
