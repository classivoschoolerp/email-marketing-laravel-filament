<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Common / site-wide settings (spec §3.25.1).
 */
class CommonSettings extends Settings
{
    // Site identity (F-3.25.1-001)
    public string $site_name;

    public ?string $site_description;

    public ?string $site_tagline;

    public ?string $site_keywords;

    // Branding (F-3.25.1-002) — paths on the `public` disk
    public ?string $logo_path;

    public ?string $favicon_path;

    // Company info (F-3.25.1-003)
    public ?string $company_name;

    public ?string $company_address;

    public ?string $company_phone;

    public ?string $company_email;

    public ?string $company_vat;

    // Localization (F-3.25.1-004, F-3.25.1-005)
    public string $default_timezone;

    public string $date_format;

    public string $time_format;

    /** 0 = Sunday … 6 = Saturday (Carbon convention). */
    public int $first_day_of_week;

    public string $default_language;

    // URLs (F-3.25.1-006)
    public bool $clean_urls;

    // Offline modes (F-3.25.1-007, F-3.25.1-008)
    public bool $site_offline;

    public string $site_offline_message;

    public bool $api_offline;

    // UI defaults (F-3.25.1-009)
    public int $grid_page_size;

    // Updates (F-3.25.1-010)
    public bool $auto_update;

    /**
     * Maintenance/offline allowlist (F-3.25.1-011).
     *
     * @var list<string>
     */
    public array $maintenance_allowed_ips;

    // reCAPTCHA (F-3.25.1-012)
    public ?string $recaptcha_site_key;

    public ?string $recaptcha_secret_key;

    /**
     * System notification recipients (F-3.25.1-013).
     *
     * @var list<string>
     */
    public array $admin_notification_emails;

    public static function group(): string
    {
        return 'common';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            'recaptcha_secret_key',
        ];
    }
}
