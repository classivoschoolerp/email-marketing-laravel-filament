<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Google Postmaster Tools settings (spec §2.9.9 F-2.9.9-005, WP-46).
 *
 * The API is authenticated with an OAuth2 SERVICE ACCOUNT: you create one in
 * Google Cloud, enable the Gmail Postmaster Tools API, then add the service
 * account's email address as a user on each domain inside Postmaster Tools.
 * Google returns nothing at all for a domain the account cannot see, which is
 * the single most common reason a sync looks "empty".
 *
 * The whole service-account JSON key is stored in ONE encrypted field rather
 * than split across columns: it is a private key, it is issued and rotated as
 * a unit, and half of one is useless. Encryption is spatie's `encrypted()`
 * contract, the same treatment D-48 gives delivery-server credentials.
 */
class PostmasterSettings extends Settings
{
    /** Master switch. Off means the scheduled sync exits immediately. */
    public bool $enabled;

    /** Raw service-account JSON key (client_email + private_key + token_uri). */
    public ?string $service_account_json;

    /**
     * Optional user to impersonate through domain-wide delegation. Leave null
     * for the normal setup, where the service account is itself a Postmaster
     * Tools user on the domain.
     */
    public ?string $delegated_user;

    /**
     * Newline-separated domains to sync. EMPTY means "every sending domain in
     * the system", which is what an operator almost always wants — an
     * explicit list exists for installs whose Postmaster access covers only
     * some of them.
     */
    public ?string $domains;

    /**
     * How many days back each sync re-fetches. Google publishes a day's stats
     * the following day and revises them for a short while afterwards, so a
     * one-day window would permanently store the first, least accurate answer.
     */
    public int $backfill_days;

    public static function group(): string
    {
        return 'postmaster';
    }

    /** @return list<string> */
    public static function encrypted(): array
    {
        return ['service_account_json'];
    }

    /**
     * Domains the operator listed, or [] meaning "all sending domains".
     *
     * @return list<string>
     */
    public function domainList(): array
    {
        $raw = trim((string) $this->domains);

        if ($raw === '') {
            return [];
        }

        $domains = preg_split('/[\s,]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (string $domain): string => mb_strtolower(trim($domain)),
            $domains,
        ))));
    }
}
