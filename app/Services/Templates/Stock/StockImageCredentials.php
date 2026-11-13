<?php

declare(strict_types=1);

namespace App\Services\Templates\Stock;

use App\Services\Templates\TemplateSettingsStore;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * API keys for the stock image libraries (spec §2.5.4 F-2.5.4-010, WP-42).
 *
 * Storage is the operator key-value store the templates module already owns
 * (`template_settings` via TemplateSettingsStore) — app/Settings is WP-04
 * territory and is not touched. The VALUE is encrypted before it goes in, in
 * the same spirit as D-48: a billable API key never sits in a column in the
 * clear, even in a table whose other rows are plain strings.
 *
 * Resolution order per provider, highest first:
 *   1. the stored (encrypted) operator key
 *   2. config('emp-templates.stock.{provider}.api_key') — the env fallback
 *
 * Never throws: a missing table (installer) or an undecryptable payload after
 * an APP_KEY rotation resolves to "not configured", which the registry turns
 * into "the picker is unavailable" rather than an error.
 */
class StockImageCredentials
{
    /** template_settings key prefix. */
    public const PREFIX = 'stock.';

    public function __construct(private readonly TemplateSettingsStore $settings) {}

    public function get(string $provider): ?string
    {
        $stored = $this->stored($provider);

        if ($stored !== null) {
            return $stored;
        }

        $fallback = config('emp-templates.stock.'.$provider.'.api_key');

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    public function has(string $provider): bool
    {
        return $this->get($provider) !== null;
    }

    /** Store (or, with null, clear) the operator key for one provider. */
    public function set(string $provider, ?string $key): void
    {
        $key = is_string($key) ? trim($key) : '';

        $this->settings->set(
            self::key($provider),
            $key === '' ? null : Crypt::encryptString($key),
        );
    }

    public static function key(string $provider): string
    {
        return self::PREFIX.$provider.'.api_key';
    }

    private function stored(string $provider): ?string
    {
        try {
            $value = $this->settings->get(self::key($provider));

            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            $plain = trim(Crypt::decryptString($value));

            return $plain === '' ? null : $plain;
        } catch (Throwable) {
            return null;
        }
    }
}
