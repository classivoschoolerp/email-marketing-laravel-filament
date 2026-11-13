<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\BlacklistSettings;

/**
 * Typed accessors for the `blacklist` settings group (spec §3.25.5).
 * Consumed by the blacklist module (WP-16).
 */
final readonly class BlacklistSettingsReader
{
    public function __construct(private BlacklistSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->enabled;
    }

    /**
     * @return list<string>
     */
    public function regexRules(): array
    {
        return $this->settings->regex_rules;
    }

    /**
     * True when any regex rule matches the given email address.
     */
    public function matchesRegexRule(string $email): bool
    {
        foreach ($this->settings->regex_rules as $pattern) {
            if (@preg_match($pattern, $email) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * One of: import | send | both.
     */
    public function applyAt(): string
    {
        return $this->settings->apply_at;
    }

    public function appliesAtImport(): bool
    {
        return $this->settings->enabled && in_array($this->settings->apply_at, [
            BlacklistSettings::APPLY_AT_IMPORT,
            BlacklistSettings::APPLY_AT_BOTH,
        ], true);
    }

    public function appliesAtSend(): bool
    {
        return $this->settings->enabled && in_array($this->settings->apply_at, [
            BlacklistSettings::APPLY_AT_SEND,
            BlacklistSettings::APPLY_AT_BOTH,
        ], true);
    }

    public function sharedAcrossCustomers(): bool
    {
        return $this->settings->shared_across_customers;
    }
}
