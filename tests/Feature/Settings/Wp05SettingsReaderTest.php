<?php

declare(strict_types=1);

use App\Services\Settings\SettingsReader;
use App\Settings\CronSettings;

it('exposes seeded blacklist defaults through the reader', function (): void {
    expect(SettingsReader::blacklist()->enabled())->toBeTrue()
        ->and(SettingsReader::blacklist()->regexRules())->toBe([])
        ->and(SettingsReader::blacklist()->applyAt())->toBe('both')
        ->and(SettingsReader::blacklist()->appliesAtImport())->toBeTrue()
        ->and(SettingsReader::blacklist()->appliesAtSend())->toBeTrue()
        ->and(SettingsReader::blacklist()->sharedAcrossCustomers())->toBeTrue();
});

it('exposes seeded twofa defaults through the reader', function (): void {
    expect(SettingsReader::twoFactor()->enabled())->toBeTrue()
        ->and(SettingsReader::twoFactor()->forceAdmins())->toBeFalse()
        ->and(SettingsReader::twoFactor()->forceCustomers())->toBeFalse()
        ->and(SettingsReader::twoFactor()->issuer())->toBe((string) config('app.name'))
        ->and(SettingsReader::twoFactor()->codeValidityWindow())->toBe(1)
        ->and(SettingsReader::twoFactor()->recoveryCodeCount())->toBe(10);
});

it('exposes seeded api defaults through the reader', function (): void {
    expect(SettingsReader::api()->enabled())->toBeTrue()
        ->and(SettingsReader::api()->requireSignature())->toBeFalse()
        ->and(SettingsReader::api()->ipAllowlist())->toBe([])
        ->and(SettingsReader::api()->ipBlocklist())->toBe([])
        ->and(SettingsReader::api()->rateLimitPerMinute())->toBe(60)
        ->and(SettingsReader::api()->rateLimitPerHour())->toBe(1000)
        ->and(SettingsReader::api()->maxRecordsPerPage())->toBe(100)
        ->and(SettingsReader::api()->corsOrigins())->toBe([])
        ->and(SettingsReader::api()->requestLoggingEnabled())->toBeFalse()
        ->and(SettingsReader::api()->requestLogRetentionDays())->toBe(30);
});

it('exposes seeded skin defaults through the reader', function (): void {
    expect(SettingsReader::skin()->skin('backend'))->toBe('default')
        ->and(SettingsReader::skin()->skin('customer'))->toBe('default')
        ->and(SettingsReader::skin()->skin('frontend'))->toBe('default')
        ->and(SettingsReader::skin()->customCss('backend'))->toBeNull()
        ->and(SettingsReader::skin()->customJs('customer'))->toBeNull()
        ->and(SettingsReader::skin()->headerHtml())->toBeNull()
        ->and(SettingsReader::skin()->footerHtml())->toBeNull()
        ->and(SettingsReader::skin()->hideBranding())->toBeFalse();
});

it('exposes seeded cdn defaults through the reader', function (): void {
    expect(SettingsReader::cdn()->enabled())->toBeFalse()
        ->and(SettingsReader::cdn()->baseUrl())->toBeNull()
        ->and(SettingsReader::cdn()->subdomain())->toBeNull()
        ->and(SettingsReader::cdn()->useForAssets())->toBeTrue()
        ->and(SettingsReader::cdn()->useForEmailImages())->toBeTrue()
        ->and(SettingsReader::cdn()->active())->toBeFalse()
        ->and(SettingsReader::cdn()->provider())->toBe('custom')
        ->and(SettingsReader::cdn()->s3Bucket())->toBeNull()
        ->and(SettingsReader::cdn()->bunnyPullZone())->toBeNull();
});

it('exposes seeded dkim defaults through the reader', function (): void {
    expect(SettingsReader::dkim()->enabled())->toBeFalse()
        ->and(SettingsReader::dkim()->selector())->toBe('default')
        ->and(SettingsReader::dkim()->privateKey())->toBeNull()
        ->and(SettingsReader::dkim()->publicKey())->toBeNull()
        ->and(SettingsReader::dkim()->forceGlobalSigning())->toBeFalse()
        ->and(SettingsReader::dkim()->keypairConfigured())->toBeFalse()
        ->and(SettingsReader::dkim()->txtRecordHost())->toBe('default._domainkey')
        ->and(SettingsReader::dkim()->spfRecommendation())->toContain('v=spf1');
});

it('exposes seeded cron defaults through the reader', function (): void {
    expect(SettingsReader::cron()->emailsPerBatch())->toBe(500)
        ->and(SettingsReader::cron()->subscribersPerBatch())->toBe(1000)
        ->and(SettingsReader::cron()->parallelProcesses())->toBe(1)
        ->and(SettingsReader::cron()->enabledJobs())->toBe(CronSettings::KNOWN_JOBS)
        ->and(SettingsReader::cron()->jobEnabled('campaigns:send'))->toBeTrue()
        ->and(SettingsReader::cron()->deliveryConcurrency())->toBe(2)
        ->and(SettingsReader::cron()->bounceConcurrency())->toBe(1);
});

it('exposes seeded license defaults through the reader', function (): void {
    expect(SettingsReader::license()->licenseKey())->toBeNull()
        ->and(SettingsReader::license()->status())->toBe('unknown')
        ->and(SettingsReader::license()->valid())->toBeFalse()
        ->and(SettingsReader::license()->type())->toBeNull()
        ->and(SettingsReader::license()->expiresAt())->toBeNull()
        ->and(SettingsReader::license()->allowedDomains())->toBe([])
        ->and(SettingsReader::license()->renewalUrl())->toBeNull()
        ->and(SettingsReader::license()->lastCheckedAt())->toBeNull();
});

it('exposes seeded social defaults through the reader', function (): void {
    expect(SettingsReader::social()->links())->toBe([])
        ->and(SettingsReader::social()->facebookUrl())->toBeNull()
        ->and(SettingsReader::social()->twitterUrl())->toBeNull()
        ->and(SettingsReader::social()->instagramUrl())->toBeNull()
        ->and(SettingsReader::social()->linkedinUrl())->toBeNull()
        ->and(SettingsReader::social()->youtubeUrl())->toBeNull()
        ->and(SettingsReader::social()->tiktokUrl())->toBeNull();
});
