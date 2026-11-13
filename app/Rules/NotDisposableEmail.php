<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;

/**
 * Disposable-email domain blocklist (spec §1.2 F-1.2-009): starter list file +
 * config-defined extras, cached per process.
 */
class NotDisposableEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! str_contains($value, '@')) {
            return; // the email rule handles format errors
        }

        $domain = mb_strtolower(trim((string) substr(strrchr($value, '@'), 1)));

        if ($domain !== '' && in_array($domain, self::domains(), true)) {
            $fail(__('auth-module.register.disposable_email'));
        }
    }

    /** @return list<string> */
    public static function domains(): array
    {
        return Cache::store('array')->rememberForever('emp.disposable-domains', function (): array {
            $domains = [];

            $file = (string) config('emp.registration.disposable_domains_file', '');

            if ($file !== '' && is_file($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = mb_strtolower(trim($line));

                    if ($line !== '' && ! str_starts_with($line, '#')) {
                        $domains[] = $line;
                    }
                }
            }

            foreach ((array) config('emp.registration.disposable_domains', []) as $extra) {
                $domains[] = mb_strtolower(trim((string) $extra));
            }

            return array_values(array_unique(array_filter($domains)));
        });
    }
}
