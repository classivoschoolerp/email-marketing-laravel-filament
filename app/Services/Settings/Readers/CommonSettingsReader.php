<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\CommonSettings;
use Illuminate\Support\Facades\Storage;

/**
 * Typed accessors for the `common` settings group (spec §3.25.1).
 */
final readonly class CommonSettingsReader
{
    public function __construct(private CommonSettings $settings) {}

    public function siteName(): string
    {
        return $this->settings->site_name;
    }

    public function siteDescription(): ?string
    {
        return $this->settings->site_description;
    }

    public function siteTagline(): ?string
    {
        return $this->settings->site_tagline;
    }

    public function siteKeywords(): ?string
    {
        return $this->settings->site_keywords;
    }

    public function logoPath(): ?string
    {
        return $this->settings->logo_path;
    }

    public function logoUrl(): ?string
    {
        return $this->settings->logo_path === null
            ? null
            : Storage::disk('public')->url($this->settings->logo_path);
    }

    public function faviconPath(): ?string
    {
        return $this->settings->favicon_path;
    }

    public function faviconUrl(): ?string
    {
        return $this->settings->favicon_path === null
            ? null
            : Storage::disk('public')->url($this->settings->favicon_path);
    }

    public function companyName(): ?string
    {
        return $this->settings->company_name;
    }

    public function companyAddress(): ?string
    {
        return $this->settings->company_address;
    }

    public function companyPhone(): ?string
    {
        return $this->settings->company_phone;
    }

    public function companyEmail(): ?string
    {
        return $this->settings->company_email;
    }

    public function companyVat(): ?string
    {
        return $this->settings->company_vat;
    }

    public function defaultTimezone(): string
    {
        return $this->settings->default_timezone;
    }

    public function dateFormat(): string
    {
        return $this->settings->date_format;
    }

    public function timeFormat(): string
    {
        return $this->settings->time_format;
    }

    public function dateTimeFormat(): string
    {
        return $this->settings->date_format.' '.$this->settings->time_format;
    }

    /**
     * 0 = Sunday … 6 = Saturday.
     */
    public function firstDayOfWeek(): int
    {
        return $this->settings->first_day_of_week;
    }

    public function defaultLanguage(): string
    {
        return $this->settings->default_language;
    }

    public function cleanUrls(): bool
    {
        return $this->settings->clean_urls;
    }

    public function siteOffline(): bool
    {
        return $this->settings->site_offline;
    }

    public function siteOfflineMessage(): string
    {
        return $this->settings->site_offline_message;
    }

    public function apiOffline(): bool
    {
        return $this->settings->api_offline;
    }

    public function gridPageSize(): int
    {
        return $this->settings->grid_page_size;
    }

    public function autoUpdate(): bool
    {
        return $this->settings->auto_update;
    }

    /**
     * @return list<string>
     */
    public function maintenanceAllowedIps(): array
    {
        return $this->settings->maintenance_allowed_ips;
    }

    public function recaptchaSiteKey(): ?string
    {
        return $this->settings->recaptcha_site_key;
    }

    public function recaptchaSecretKey(): ?string
    {
        return $this->settings->recaptcha_secret_key;
    }

    public function recaptchaConfigured(): bool
    {
        return $this->settings->recaptcha_site_key !== null
            && $this->settings->recaptcha_secret_key !== null;
    }

    /**
     * @return list<string>
     */
    public function adminNotificationEmails(): array
    {
        return $this->settings->admin_notification_emails;
    }
}
