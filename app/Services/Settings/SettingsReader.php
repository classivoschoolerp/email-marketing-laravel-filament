<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Services\Settings\Readers\ApiSettingsReader;
use App\Services\Settings\Readers\BlacklistSettingsReader;
use App\Services\Settings\Readers\CampaignSettingsReader;
use App\Services\Settings\Readers\CdnSettingsReader;
use App\Services\Settings\Readers\CommonSettingsReader;
use App\Services\Settings\Readers\CronSettingsReader;
use App\Services\Settings\Readers\CustomerSettingsReader;
use App\Services\Settings\Readers\DkimSettingsReader;
use App\Services\Settings\Readers\EnterpriseSsoSettingsReader;
use App\Services\Settings\Readers\ImportExportSettingsReader;
use App\Services\Settings\Readers\LicenseSettingsReader;
use App\Services\Settings\Readers\LocationSettingsReader;
use App\Services\Settings\Readers\MarketingSettingsReader;
use App\Services\Settings\Readers\OpsSettingsReader;
use App\Services\Settings\Readers\PrivacySettingsReader;
use App\Services\Settings\Readers\SecuritySettingsReader;
use App\Services\Settings\Readers\SkinSettingsReader;
use App\Services\Settings\Readers\SocialSettingsReader;
use App\Services\Settings\Readers\SsoSettingsReader;
use App\Services\Settings\Readers\TwoFactorSettingsReader;
use App\Settings\ApiSettings;
use App\Settings\BlacklistSettings;
use App\Settings\CampaignSettings;
use App\Settings\CdnSettings;
use App\Settings\CommonSettings;
use App\Settings\CronSettings;
use App\Settings\CustomerSettings;
use App\Settings\DkimSettings;
use App\Settings\EnterpriseSsoSettings;
use App\Settings\ImportExportSettings;
use App\Settings\LicenseSettings;
use App\Settings\LocationSettings;
use App\Settings\MarketingSettings;
use App\Settings\OpsSettings;
use App\Settings\PrivacySettings;
use App\Settings\SecuritySettings;
use App\Settings\SkinSettings;
use App\Settings\SocialSettings;
use App\Settings\SsoSettings;
use App\Settings\TwoFactorSettings;

/**
 * Facade-style convenience accessors over the §3.25 settings groups.
 *
 * Usage: `SettingsReader::campaign()->maxAttachmentSizeMb()`.
 *
 * Each call resolves the container-scoped settings instance, so values are read
 * once per request and always reflect the latest saved state.
 */
final class SettingsReader
{
    public static function common(): CommonSettingsReader
    {
        return new CommonSettingsReader(app(CommonSettings::class));
    }

    public static function importExport(): ImportExportSettingsReader
    {
        return new ImportExportSettingsReader(app(ImportExportSettings::class));
    }

    public static function campaign(): CampaignSettingsReader
    {
        return new CampaignSettingsReader(app(CampaignSettings::class));
    }

    public static function customer(): CustomerSettingsReader
    {
        return new CustomerSettingsReader(app(CustomerSettings::class));
    }

    public static function blacklist(): BlacklistSettingsReader
    {
        return new BlacklistSettingsReader(app(BlacklistSettings::class));
    }

    public static function twoFactor(): TwoFactorSettingsReader
    {
        return new TwoFactorSettingsReader(app(TwoFactorSettings::class));
    }

    public static function api(): ApiSettingsReader
    {
        return new ApiSettingsReader(app(ApiSettings::class));
    }

    public static function skin(): SkinSettingsReader
    {
        return new SkinSettingsReader(app(SkinSettings::class));
    }

    public static function cdn(): CdnSettingsReader
    {
        return new CdnSettingsReader(app(CdnSettings::class));
    }

    public static function dkim(): DkimSettingsReader
    {
        return new DkimSettingsReader(app(DkimSettings::class));
    }

    public static function cron(): CronSettingsReader
    {
        return new CronSettingsReader(app(CronSettings::class));
    }

    public static function license(): LicenseSettingsReader
    {
        return new LicenseSettingsReader(app(LicenseSettings::class));
    }

    public static function social(): SocialSettingsReader
    {
        return new SocialSettingsReader(app(SocialSettings::class));
    }

    /** WP-38 §1.1 / §1.11 — public marketing site + blog. */
    public static function marketing(): MarketingSettingsReader
    {
        return new MarketingSettingsReader(app(MarketingSettings::class));
    }

    /** WP-38 §6.7 — one-time login tokens + social login. */
    public static function sso(): SsoSettingsReader
    {
        return new SsoSettingsReader(app(SsoSettings::class));
    }

    /** WP-46 §6.7 F-6.7-002 — generic OIDC + SAML 2.0 enterprise SSO. */
    public static function enterpriseSso(): EnterpriseSsoSettingsReader
    {
        return new EnterpriseSsoSettingsReader(app(EnterpriseSsoSettings::class));
    }

    /** §3.23 location services (WP-24, D-10). */
    public static function location(): LocationSettingsReader
    {
        return new LocationSettingsReader(app(LocationSettings::class));
    }

    /** §6.6 operations & admin health (WP-24). */
    public static function ops(): OpsSettingsReader
    {
        return new OpsSettingsReader(app(OpsSettings::class));
    }

    /** §3.25.15 security settings (WP-22). */
    public static function security(): SecuritySettingsReader
    {
        return new SecuritySettingsReader(app(SecuritySettings::class));
    }

    /** §3.25.16 privacy / GDPR settings (WP-22). */
    public static function privacy(): PrivacySettingsReader
    {
        return new PrivacySettingsReader(app(PrivacySettings::class));
    }
}
