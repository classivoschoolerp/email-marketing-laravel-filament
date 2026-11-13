<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Inbox rendering preview credentials (spec §2.3.5 F-2.3.5-006, WP-46).
 *
 * A NEW settings group rather than columns on an existing one, because these
 * are third-party API secrets and secrets belong in an encrypted store — never
 * in `config/*.php`, where they would end up in version control and in
 * `config:cache` output. Encryption uses spatie's `encrypted()` contract, the
 * same treatment D-48 gives delivery-server credentials.
 *
 * Ships EMPTY and therefore disabled: an install with no keys resolves
 * NullProvider and the UI says a Litmus or Email on Acid account is required.
 * A migration must never make an install start shipping campaign HTML to a
 * third party.
 *
 * Base URLs are configurable for the same reason D-76 made them configurable
 * for the verification adapters: it is the only way to point an adapter at a
 * sandbox, and it is what makes the Http::fake contract tests meaningful.
 */
class InboxPreviewSettings extends Settings
{
    public const DEFAULT_LITMUS_BASE = 'https://%s.litmus.com/api/v1';

    public const DEFAULT_EMAIL_ON_ACID_BASE = 'https://api.emailonacid.com/v5';

    /** Provider key selected as the default (`litmus`, `email_on_acid`, `null`). */
    public string $provider;

    /* ---- Litmus ------------------------------------------------------ */

    /** Litmus account subdomain, i.e. the `acme` in acme.litmus.com. */
    public ?string $litmus_subdomain;

    /** Litmus API key (used as the HTTP Basic username). */
    public ?string $litmus_api_key;

    /** Litmus test set, e.g. `populardesktop`. Null = the account default. */
    public ?string $litmus_test_set;

    /** Override for the Litmus base URL; `%s` is the subdomain. */
    public ?string $litmus_base_url;

    /* ---- Email on Acid ----------------------------------------------- */

    public ?string $email_on_acid_api_user;

    public ?string $email_on_acid_api_key;

    public ?string $email_on_acid_base_url;

    /** Newline/comma separated client keys; empty = the vendor default set. */
    public ?string $email_on_acid_clients;

    public static function group(): string
    {
        return 'inbox_preview';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['litmus_api_key', 'email_on_acid_api_key'];
    }

    public function litmusBaseUrl(): string
    {
        $override = trim((string) $this->litmus_base_url);

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim(sprintf(self::DEFAULT_LITMUS_BASE, (string) $this->litmus_subdomain), '/');
    }

    public function emailOnAcidBaseUrl(): string
    {
        $override = trim((string) $this->email_on_acid_base_url);

        return rtrim($override !== '' ? $override : self::DEFAULT_EMAIL_ON_ACID_BASE, '/');
    }

    /** @return list<string> */
    public function emailOnAcidClientList(): array
    {
        $raw = trim((string) $this->email_on_acid_clients);

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $key): string => trim($key),
            preg_split('/[\s,]+/', $raw) ?: [],
        )));
    }
}
