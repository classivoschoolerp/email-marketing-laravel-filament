<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\PasswordHistory;
use App\Services\Settings\SettingsReader;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * The single source of truth for password strength + reuse prevention
 * (spec §3.25.15 F-3.25.15-003, WP-22).
 *
 * Two halves, deliberately split:
 *  - {@see strength()} builds the Illuminate Password rule from the security
 *    settings (min length + complexity flags). It also feeds
 *    `Password::defaults()`, so every existing caller — the admin Security
 *    page, CustomerResource, UserResource — becomes settings-aware without
 *    being touched.
 *  - the rule object itself enforces reuse prevention against
 *    password_histories for one specific account.
 *
 * `rules()` returns both, and is what the password-change paths use.
 */
final class PasswordPolicy implements ValidationRule
{
    public function __construct(private readonly Authenticatable|Model|null $user = null) {}

    /**
     * Strength + reuse rules for a password field.
     *
     * @return array<int, mixed>
     */
    public static function rules(Authenticatable|Model|null $user = null): array
    {
        return [self::strength(), new self($user)];
    }

    /**
     * Illuminate strength rule built from the §3.25.15 settings, falling
     * back to the WP-02 defaults (min 8, mixed case, numbers) whenever the
     * settings group has not been migrated yet.
     */
    public static function strength(): Password
    {
        try {
            $security = SettingsReader::security();

            $rule = Password::min($security->passwordMinLength());

            if ($security->passwordRequiresMixedCase()) {
                $rule = $rule->mixedCase();
            }

            if ($security->passwordRequiresNumbers()) {
                $rule = $rule->numbers();
            }

            if ($security->passwordRequiresSymbols()) {
                $rule = $rule->symbols();
            }

            return $rule;
        } catch (Throwable) {
            return Password::min(8)->mixedCase()->numbers();
        }
    }

    /**
     * Reuse prevention: the candidate must not match the account's current
     * password nor its last N recorded hashes (N = password_reuse_prevention,
     * 0 = disabled per D-23).
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '' || $this->user === null) {
            return;
        }

        try {
            $depth = SettingsReader::security()->passwordReusePrevention();
        } catch (Throwable) {
            return; // settings not migrated — nothing to enforce
        }

        if ($depth <= 0) {
            return;
        }

        foreach ($this->recentHashes($depth) as $hash) {
            if ($hash !== '' && Hash::check($value, $hash)) {
                $fail(__('settings.security.validation.password_reused', ['count' => $depth]));

                return;
            }
        }
    }

    /**
     * The current hash plus the last N-1 historical ones — the current
     * password always counts as "reused", whatever the history holds.
     *
     * @return array<int, string>
     */
    private function recentHashes(int $depth): array
    {
        $model = $this->user;

        $hashes = [];

        if ($model instanceof Model) {
            $current = (string) ($model->getAttribute('password') ?? '');

            if ($current !== '') {
                $hashes[] = $current;
            }

            $hashes = array_merge($hashes, PasswordHistory::query()
                ->where('authenticatable_type', $model->getMorphClass())
                ->where('authenticatable_id', $model->getKey())
                ->orderByDesc('id')
                ->limit($depth)
                ->pluck('password_hash')
                ->map(static fn (mixed $hash): string => (string) $hash)
                ->all());
        }

        return array_slice(array_values(array_unique($hashes)), 0, $depth);
    }
}
