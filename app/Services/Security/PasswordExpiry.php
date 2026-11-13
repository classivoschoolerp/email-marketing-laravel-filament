<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Services\Settings\SettingsReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Password expiry evaluation (spec §3.25.15 F-3.25.15-003, WP-22).
 *
 * The reference timestamp is `password_changed_at`, falling back to the
 * account's `created_at` when a password has never been changed since the
 * column was introduced. 0 expiry days = passwords never expire (D-23).
 */
final class PasswordExpiry
{
    public function enabled(): bool
    {
        return $this->days() > 0;
    }

    public function isExpired(?Model $user): bool
    {
        if ($user === null || ! $this->enabled()) {
            return false;
        }

        $expiresAt = $this->expiresAt($user);

        return $expiresAt !== null && $expiresAt->isPast();
    }

    public function expiresAt(?Model $user): ?Carbon
    {
        if ($user === null || ! $this->enabled()) {
            return null;
        }

        $changed = $user->getAttribute('password_changed_at') ?? $user->getAttribute('created_at');

        if ($changed === null) {
            return null;
        }

        return Carbon::parse($changed)->addDays($this->days());
    }

    private function days(): int
    {
        try {
            return SettingsReader::security()->passwordExpiryDays();
        } catch (Throwable) {
            return 0;
        }
    }
}
