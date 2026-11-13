<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Services\Blacklist\BlacklistImportGuard;
use App\Services\Blacklist\BlacklistMatcher;
use App\Services\Imports\ImportGuard;
use App\Services\Imports\SubscriberImporter;
use App\Settings\BlacklistSettings;
use Illuminate\Support\Carbon;
use Tests\Fakes\RecordingSendingDriver;

/*
 * WP-16 §3.17 enforcement matrix: exact rows + domain rows + regex rules ×
 * apply-at-import (BlacklistImportGuard on the WP-08 seam) and
 * apply-at-send (BlacklistSendGuard hook in SendToSubscriberJob), honoring
 * the shared-vs-per-customer isolation mode.
 */

function wp16_blacklist_settings(array $overrides = []): void
{
    $settings = app(BlacklistSettings::class);
    $settings->enabled = $overrides['enabled'] ?? true;
    $settings->regex_rules = $overrides['regex_rules'] ?? [];
    $settings->apply_at = $overrides['apply_at'] ?? BlacklistSettings::APPLY_AT_BOTH;
    $settings->shared_across_customers = $overrides['shared'] ?? true;
    $settings->save();
}

it('replaces the Null ImportGuard binding with the real blacklist guard', function (): void {
    expect(app(ImportGuard::class))->toBeInstanceOf(BlacklistImportGuard::class);
});

// ---------------------------------------------------------------------------
// Matcher matrix: exact / domain / regex rows (F-3.17-005/006)
// ---------------------------------------------------------------------------
it('matches exact, domain and regex rules through the one matcher', function (): void {
    wp16_blacklist_settings(['regex_rules' => ['/^spam-/i']]);

    GlobalBlacklistEmail::query()->create(['email' => 'Bad@Example.com']);
    GlobalBlacklistEmail::query()->create(['email' => '@blocked.example', 'is_domain' => true]);

    $matcher = app(BlacklistMatcher::class);

    expect($matcher->matchType('bad@example.com'))->toBe(BlacklistMatcher::MATCH_EXACT)
        ->and($matcher->matchType('BAD@EXAMPLE.COM'))->toBe(BlacklistMatcher::MATCH_EXACT)
        ->and($matcher->matchType('anyone@blocked.example'))->toBe(BlacklistMatcher::MATCH_DOMAIN)
        ->and($matcher->matchType('spam-lord@clean.example'))->toBe(BlacklistMatcher::MATCH_REGEX)
        ->and($matcher->matchType('friend@clean.example'))->toBeNull();
});

it('matches nothing when the blacklist feature is disabled', function (): void {
    wp16_blacklist_settings(['enabled' => false, 'regex_rules' => ['/^spam-/i']]);

    GlobalBlacklistEmail::query()->create(['email' => 'bad@example.com']);

    expect(app(BlacklistMatcher::class)->matches('bad@example.com'))->toBeFalse()
        ->and(app(BlacklistMatcher::class)->matches('spam-x@x.example'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Shared vs per-customer isolation (F-3.25.5-004)
// ---------------------------------------------------------------------------
it('honors isolation mode: foreign rows only bind in shared mode, global rows always do', function (): void {
    $mine = Customer::factory()->create();
    $other = Customer::factory()->create();

    GlobalBlacklistEmail::query()->create(['email' => 'foreign@example.com', 'customer_id' => $other->id]);
    GlobalBlacklistEmail::query()->create(['email' => 'own@example.com', 'customer_id' => $mine->id]);
    GlobalBlacklistEmail::query()->create(['email' => 'global@example.com']);

    $matcher = app(BlacklistMatcher::class);

    wp16_blacklist_settings(['shared' => false]);

    expect($matcher->matches('foreign@example.com', $mine->id))->toBeFalse()
        ->and($matcher->matches('own@example.com', $mine->id))->toBeTrue()
        ->and($matcher->matches('global@example.com', $mine->id))->toBeTrue();

    wp16_blacklist_settings(['shared' => true]);

    expect($matcher->matches('foreign@example.com', $mine->id))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Apply-at-import through the real importer (F-2.2.7-010 / F-3.17-007 seam)
// ---------------------------------------------------------------------------
it('skips blacklisted addresses at import only when apply-at covers import', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();

    GlobalBlacklistEmail::query()->create(['email' => 'bad@example.com']);
    GlobalBlacklistEmail::query()->create(['email' => 'domain.example', 'is_domain' => true]);

    $import = fn (string $email): array => app(SubscriberImporter::class)->importRow(
        $list,
        $email,
        [],
        SubscriberStatus::Confirmed,
        'skip',
    );

    wp16_blacklist_settings(['apply_at' => BlacklistSettings::APPLY_AT_IMPORT, 'regex_rules' => ['/^noreply@/i']]);

    expect($import('bad@example.com')['result'])->toBe(SubscriberImporter::RESULT_SKIPPED)
        ->and($import('user@domain.example')['result'])->toBe(SubscriberImporter::RESULT_SKIPPED)
        ->and($import('noreply@clean.example')['result'])->toBe(SubscriberImporter::RESULT_SKIPPED)
        ->and($import('fine@clean.example')['result'])->toBe(SubscriberImporter::RESULT_IMPORTED);

    // apply_at = send → imports pass untouched.
    wp16_blacklist_settings(['apply_at' => BlacklistSettings::APPLY_AT_SEND]);

    expect($import('bad2@example.com')['result'])->toBe(SubscriberImporter::RESULT_IMPORTED);
});

// ---------------------------------------------------------------------------
// Apply-at-send through the real pipeline (§3.17 enforcement + WP-13 hook)
// ---------------------------------------------------------------------------
function wp16_pipeline(): array
{
    config(['app.url' => 'http://localhost']);

    $list = EmailList::factory()->create([
        'company_name' => 'Acme Inc.',
        'company_address_1' => '1 Acme Way',
        'company_city' => 'Springfield',
        'company_country' => 'US',
    ]);

    $blocked = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'email' => 'blocked@example.com',
    ]);
    $clean = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'email' => 'clean@example.com',
    ]);

    DeliveryServer::factory()->create([
        'customer_id' => $list->customer_id,
        'type' => 'smtp',
    ]);

    $driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $driver);

    $campaign = Campaign::factory()->create([
        'customer_id' => $list->customer_id,
        'email_list_id' => $list->id,
        'status' => CampaignStatus::Scheduled,
        'send_at' => Carbon::now()->subMinute(),
    ]);

    return [$campaign, $blocked, $clean, $driver];
}

it('skips globally blacklisted recipients at send time with a durable skip log', function (): void {
    [$campaign, $blocked, $clean, $driver] = wp16_pipeline();

    wp16_blacklist_settings(['apply_at' => BlacklistSettings::APPLY_AT_SEND]);
    GlobalBlacklistEmail::query()->create(['email' => 'blocked@example.com']);

    $this->artisan('campaigns:send')->assertSuccessful();

    expect($driver->sent)->toHaveCount(1)
        ->and($driver->sent[0]['message']->firstRecipient())->toBe('clean@example.com');

    $log = CampaignDeliveryLog::query()
        ->where('campaign_id', $campaign->id)
        ->where('subscriber_id', $blocked->id)
        ->firstOrFail();

    expect($log->status)->toBe(CampaignDeliveryLog::STATUS_SKIPPED)
        ->and($log->response)->toBe(__('blacklist.log.send_skipped'));
});

it('also blocks domain rows and regex rules at send time', function (): void {
    [, , , $driver] = wp16_pipeline();

    wp16_blacklist_settings([
        'apply_at' => BlacklistSettings::APPLY_AT_BOTH,
        'regex_rules' => ['/^clean@/'],
    ]);
    GlobalBlacklistEmail::query()->create(['email' => 'example.com', 'is_domain' => true]);

    $this->artisan('campaigns:send')->assertSuccessful();

    // blocked@example.com by the domain row, clean@example.com by regex.
    expect($driver->sent)->toHaveCount(0);
});

it('does not touch the send path when apply-at is import-only', function (): void {
    [, , , $driver] = wp16_pipeline();

    wp16_blacklist_settings(['apply_at' => BlacklistSettings::APPLY_AT_IMPORT]);
    GlobalBlacklistEmail::query()->create(['email' => 'blocked@example.com']);

    $this->artisan('campaigns:send')->assertSuccessful();

    expect($driver->sent)->toHaveCount(2);
});

it('ignores another tenant\'s rows at send time in isolation mode', function (): void {
    [, , , $driver] = wp16_pipeline();

    $other = Customer::factory()->create();

    wp16_blacklist_settings(['shared' => false]);
    GlobalBlacklistEmail::query()->create(['email' => 'blocked@example.com', 'customer_id' => $other->id]);

    $this->artisan('campaigns:send')->assertSuccessful();

    expect($driver->sent)->toHaveCount(2);
});
