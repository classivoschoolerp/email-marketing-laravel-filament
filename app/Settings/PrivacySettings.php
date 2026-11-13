<?php

declare(strict_types=1);

namespace App\Settings;

use App\Console\Commands\LogsPruneCommand;
use App\Http\Controllers\Public\PrivacyController;
use App\Support\Privacy\IpAnonymizer;
use Spatie\LaravelSettings\Settings;

/**
 * Privacy / GDPR settings (spec §3.25.16, WP-22) plus the §4.5 compliance
 * switches that have no home of their own (CAN-SPAM enforcement,
 * portability export, sub-processor list).
 *
 * Retention values are nullable on purpose: NULL = "inherit the
 * config/emp-sending.php window" (the WP-13 source), an integer overrides it
 * and 0 means keep forever (D-23).
 *
 * Consumers:
 *  - F-3.25.16-001 → resources/views/components/public-layout.blade.php
 *  - F-3.25.16-002 → {@see LogsPruneCommand}
 *  - F-3.25.16-003 → {@see PrivacyController}
 *  - F-3.25.16-004 → /legal/dpa public page
 *  - F-3.25.16-005 → {@see IpAnonymizer}
 */
class PrivacySettings extends Settings
{
    // F-3.25.16-001 — cookie consent banner.
    public bool $cookie_consent_enabled;

    public ?string $cookie_consent_text;

    public ?string $cookie_policy_url;

    // F-3.25.16-002 — retention per data type (days). NULL = config fallback.
    public ?int $retention_delivery_log_days;

    public ?int $retention_open_days;

    public ?int $retention_click_days;

    public ?int $retention_export_days;

    // F-3.25.16-003 — public right-to-erasure workflow.
    public bool $erasure_workflow_enabled;

    // F-3.25.16-004 — data processing agreement + sub-processors (§4.5).
    public ?string $dpa_text;

    public ?string $sub_processors_text;

    // F-3.25.16-005 — anonymise IPs at every capture point.
    public bool $anonymize_ips;

    /** §4.5 GDPR portability: subscriber self-service data download. */
    public bool $data_export_enabled;

    /** §4.5 CAN-SPAM: block scheduling when the list has no postal address. */
    public bool $canspam_enforce_address;

    public static function group(): string
    {
        return 'privacy';
    }
}
