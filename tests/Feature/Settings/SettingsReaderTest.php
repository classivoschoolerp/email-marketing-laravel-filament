<?php

declare(strict_types=1);

use App\Models\CustomerGroup;
use App\Services\Settings\SettingsReader;
use App\Settings\CampaignSettings;
use App\Settings\CommonSettings;
use App\Settings\CustomerSettings;

it('exposes seeded common defaults through the reader', function (): void {
    expect(SettingsReader::common()->siteName())->toBe('Email Marketing Platform')
        ->and(SettingsReader::common()->defaultTimezone())->toBe('UTC')
        ->and(SettingsReader::common()->dateFormat())->toBe('Y-m-d')
        ->and(SettingsReader::common()->timeFormat())->toBe('H:i')
        ->and(SettingsReader::common()->dateTimeFormat())->toBe('Y-m-d H:i')
        ->and(SettingsReader::common()->firstDayOfWeek())->toBe(1)
        ->and(SettingsReader::common()->defaultLanguage())->toBe('en')
        ->and(SettingsReader::common()->cleanUrls())->toBeTrue()
        ->and(SettingsReader::common()->siteOffline())->toBeFalse()
        ->and(SettingsReader::common()->apiOffline())->toBeFalse()
        ->and(SettingsReader::common()->gridPageSize())->toBe(25)
        ->and(SettingsReader::common()->autoUpdate())->toBeFalse()
        ->and(SettingsReader::common()->maintenanceAllowedIps())->toBe([])
        ->and(SettingsReader::common()->recaptchaConfigured())->toBeFalse()
        ->and(SettingsReader::common()->adminNotificationEmails())->toBe([])
        ->and(SettingsReader::common()->logoPath())->toBeNull()
        ->and(SettingsReader::common()->logoUrl())->toBeNull()
        ->and(SettingsReader::common()->faviconUrl())->toBeNull();
});

it('exposes seeded import/export defaults through the reader', function (): void {
    expect(SettingsReader::importExport()->maxImportFileSizeMb())->toBe(50)
        ->and(SettingsReader::importExport()->allowedImportExtensions())->toBe(['csv', 'txt', 'xlsx'])
        ->and(SettingsReader::importExport()->importBatchSize())->toBe(500)
        ->and(SettingsReader::importExport()->importMemoryLimitMb())->toBe(256)
        ->and(SettingsReader::importExport()->importMaxExecutionSeconds())->toBe(300)
        ->and(SettingsReader::importExport()->exportBatchSize())->toBe(1000)
        ->and(SettingsReader::importExport()->exportMaxRowsPerFile())->toBe(100000)
        ->and(SettingsReader::importExport()->importFromUrlEnabled())->toBeFalse()
        ->and(SettingsReader::importExport()->importFromDatabaseEnabled())->toBeFalse()
        ->and(SettingsReader::importExport()->exportRetentionDays())->toBe(7);
});

it('exposes seeded campaign defaults through the reader', function (): void {
    expect(SettingsReader::campaign()->attachmentsEnabled())->toBeTrue()
        ->and(SettingsReader::campaign()->maxAttachmentSizeMb())->toBe(10)
        ->and(SettingsReader::campaign()->maxAttachmentCount())->toBe(5)
        ->and(SettingsReader::campaign()->enabledTemplateTags())->toBe(CampaignSettings::KNOWN_TEMPLATE_TAGS)
        ->and(SettingsReader::campaign()->templateTagEnabled('UNSUBSCRIBE_URL'))->toBeTrue()
        ->and(SettingsReader::campaign()->templateTagEnabled('NOT_A_TAG'))->toBeFalse()
        ->and(SettingsReader::campaign()->blacklistedWordsAction())->toBe('block')
        ->and(SettingsReader::campaign()->blacklistedWordsBlock())->toBeTrue()
        ->and(SettingsReader::campaign()->webhooksEnabled())->toBeFalse()
        ->and(SettingsReader::campaign()->defaultOpenTracking())->toBeTrue()
        ->and(SettingsReader::campaign()->defaultClickTracking())->toBeTrue()
        ->and(SettingsReader::campaign()->maxCampaignRecipients())->toBe(0)
        ->and(SettingsReader::campaign()->approvalRequired())->toBeFalse()
        ->and(SettingsReader::campaign()->sendAtMinOffsetMinutes())->toBe(0)
        ->and(SettingsReader::campaign()->bounceAutoPausePercent())->toBe(5.0)
        ->and(SettingsReader::campaign()->complaintAutoPausePercent())->toBe(0.5)
        ->and(SettingsReader::campaign()->defaultPlainTextGeneration())->toBeTrue()
        ->and(SettingsReader::campaign()->retryMaxAttempts())->toBe(3)
        ->and(SettingsReader::campaign()->retryBackoffSeconds())->toBe(300);
});

it('exposes seeded customer defaults through the reader', function (): void {
    expect(SettingsReader::customer()->registrationEnabled())->toBeTrue()
        ->and(SettingsReader::customer()->registrationRequiresApproval())->toBeFalse()
        ->and(SettingsReader::customer()->registrationRequiresEmailConfirmation())->toBeTrue()
        ->and(SettingsReader::customer()->defaultCustomerGroupId())->toBeNull()
        ->and(SettingsReader::customer()->defaultCustomerGroup())->toBeNull()
        ->and(SettingsReader::customer()->allowedSendingServerTypes())->toBe(CustomerSettings::SENDING_SERVER_TYPES)
        ->and(SettingsReader::customer()->sendingServerTypeAllowed('smtp'))->toBeTrue()
        ->and(SettingsReader::customer()->sendingServerTypeAllowed('carrier-pigeon'))->toBeFalse()
        ->and(SettingsReader::customer()->defaultMaxLists())->toBe(0)
        ->and(SettingsReader::customer()->defaultSendingQuota())->toBe(0)
        ->and(SettingsReader::customer()->defaultSendingQuotaPeriod())->toBe('monthly')
        ->and(SettingsReader::customer()->defaultCdnEnabled())->toBeFalse()
        ->and(SettingsReader::customer()->defaultApiEnabled())->toBeTrue()
        ->and(SettingsReader::customer()->defaultApiRateLimitPerMinute())->toBe(60)
        ->and(SettingsReader::customer()->forceSystemServersOnly())->toBeFalse()
        ->and(SettingsReader::customer()->welcomeEmailEnabled())->toBeTrue()
        ->and(SettingsReader::customer()->selfServeDeletionEnabled())->toBeTrue()
        ->and(SettingsReader::customer()->termsAcceptanceRequired())->toBeTrue()
        ->and(SettingsReader::customer()->minimumPasswordStrength())->toBe('medium')
        ->and(SettingsReader::customer()->inactiveAutoSuspendDays())->toBe(0);
});

it('reflects saved changes immediately', function (): void {
    $settings = app(CommonSettings::class);
    $settings->site_name = 'Renamed Platform';
    $settings->save();

    expect(SettingsReader::common()->siteName())->toBe('Renamed Platform');
});

it('resolves the default customer group relation', function (): void {
    $group = CustomerGroup::factory()->create();

    $settings = app(CustomerSettings::class);
    $settings->default_customer_group_id = $group->id;
    $settings->save();

    expect(SettingsReader::customer()->defaultCustomerGroupId())->toBe($group->id)
        ->and(SettingsReader::customer()->defaultCustomerGroup()?->is($group))->toBeTrue();
});
