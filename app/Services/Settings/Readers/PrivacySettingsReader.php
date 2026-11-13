<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\PrivacySettings;

/**
 * Typed accessors for the `privacy` settings group (spec §3.25.16 + §4.5).
 *
 * The retention accessors implement the NULL = inherit-config contract: a
 * NULL setting resolves to the WP-13 config/emp-sending.php window, an
 * integer overrides it, and 0 means keep forever (D-23).
 */
final readonly class PrivacySettingsReader
{
    public function __construct(private PrivacySettings $settings) {}

    // ------------------------------------------- F-3.25.16-001 cookie banner

    public function cookieConsentEnabled(): bool
    {
        return $this->settings->cookie_consent_enabled;
    }

    public function cookieConsentText(): string
    {
        $text = trim((string) $this->settings->cookie_consent_text);

        return $text === '' ? __('privacy.cookies.default_text') : $text;
    }

    public function cookiePolicyUrl(): ?string
    {
        $url = trim((string) $this->settings->cookie_policy_url);

        return $url === '' ? null : $url;
    }

    // ---------------------------------------------- F-3.25.16-002 retention

    public function deliveryLogRetentionDays(): int
    {
        return $this->settings->retention_delivery_log_days
            ?? (int) config('emp-sending.retention.campaign_delivery_log_days', 90);
    }

    public function openRetentionDays(): int
    {
        return $this->settings->retention_open_days
            ?? (int) config('emp-sending.retention.campaign_track_days', 180);
    }

    public function clickRetentionDays(): int
    {
        return $this->settings->retention_click_days
            ?? (int) config('emp-sending.retention.campaign_track_days', 180);
    }

    /** 0 = leave export expiry to §3.25.2 / exports:prune. */
    public function exportRetentionDays(): int
    {
        return max(0, $this->settings->retention_export_days ?? 0);
    }

    // ----------------------------------------------- F-3.25.16-003 erasure

    public function erasureWorkflowEnabled(): bool
    {
        return $this->settings->erasure_workflow_enabled;
    }

    // --------------------------------------------------- F-3.25.16-004 DPA

    public function dpaText(): ?string
    {
        $text = trim((string) $this->settings->dpa_text);

        return $text === '' ? null : $text;
    }

    public function subProcessorsText(): ?string
    {
        $text = trim((string) $this->settings->sub_processors_text);

        return $text === '' ? null : $text;
    }

    // ------------------------------------------- F-3.25.16-005 anonymise IPs

    public function anonymizeIps(): bool
    {
        return $this->settings->anonymize_ips;
    }

    // ----------------------------------------------------------- §4.5 rows

    public function dataExportEnabled(): bool
    {
        return $this->settings->data_export_enabled;
    }

    public function canSpamEnforceAddress(): bool
    {
        return $this->settings->canspam_enforce_address;
    }
}
