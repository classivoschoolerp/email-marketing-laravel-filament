<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Sending\Message;
use App\Services\Campaigns\CampaignPreflight;
use App\Settings\PrivacySettings;
use Illuminate\Support\Carbon;
use Tests\Fakes\RecordingSendingDriver;

/*
 * WP-22 — F-4.5-002 CAN-SPAM: physical address footer ENFORCEMENT,
 * unsubscribe honoured immediately (well inside the 10-day statutory
 * window), and honest From/Reply-To headers.
 */

function canspamList(array $overrides = []): EmailList
{
    return EmailList::factory()->create(array_merge([
        'visible' => true,
        'status' => EmailList::STATUS_ACTIVE,
        'company_name' => 'Acme Inc.',
        'company_address_1' => '1 Acme Way',
        'company_city' => 'Springfield',
        'company_country' => 'US',
    ], $overrides));
}

function canspamCampaign(EmailList $list, array $overrides = []): Campaign
{
    return Campaign::factory()->create(array_merge([
        'customer_id' => $list->customer_id,
        'email_list_id' => $list->id,
        'status' => CampaignStatus::Scheduled,
        'send_at' => Carbon::now()->subMinute(),
        'subject' => 'Hello',
        'from_name' => 'Acme',
        'from_email' => 'news@acme.test',
        'reply_to' => 'replies@acme.test',
        'content_html' => '<html><body><p>Hi</p><p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p></body></html>',
    ], $overrides));
}

// ------------------------------------------- F-4.5-002 (a) address enforcement

it('does not check the postal address while enforcement is off', function (): void {
    $list = canspamList(['company_address_1' => null, 'company_city' => null, 'company_zip' => null]);
    $campaign = canspamCampaign($list);

    $keys = array_column(app(CampaignPreflight::class)->checks($campaign), 'key');

    expect($keys)->not->toContain('company_address')
        ->and(app(CampaignPreflight::class)->passes($campaign))->toBeTrue();
});

it('blocks scheduling a campaign whose list has no postal address', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->canspam_enforce_address = true;
    $settings->save();

    $list = canspamList(['company_address_1' => null, 'company_city' => null, 'company_zip' => null]);
    $campaign = canspamCampaign($list);

    $preflight = app(CampaignPreflight::class);
    $check = collect($preflight->checks($campaign))->firstWhere('key', 'company_address');

    expect($check)->not->toBeNull()
        ->and($check['passed'])->toBeFalse()
        ->and($check['level'])->toBe(CampaignPreflight::LEVEL_ERROR)
        ->and($preflight->passes($campaign))->toBeFalse()
        ->and($preflight->failures($campaign))->toContain(__('campaigns.preflight.company_address'));
});

it('passes once the list carries a street and a locality', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->canspam_enforce_address = true;
    $settings->save();

    $campaign = canspamCampaign(canspamList());

    $preflight = app(CampaignPreflight::class);
    $check = collect($preflight->checks($campaign))->firstWhere('key', 'company_address');

    expect($check['passed'])->toBeTrue()
        ->and($preflight->passes($campaign))->toBeTrue();
});

it('rejects a street line without a city or postcode', function (): void {
    $settings = app(PrivacySettings::class);
    $settings->canspam_enforce_address = true;
    $settings->save();

    $campaign = canspamCampaign(canspamList([
        'company_address_1' => '1 Acme Way',
        'company_city' => null,
        'company_zip' => null,
    ]));

    expect(app(CampaignPreflight::class)->passes($campaign))->toBeFalse();
});

// ------------------------------ F-4.5-002 (b) unsubscribe honoured immediately

it('flips the subscriber immediately and skips them on the next send', function (): void {
    $list = canspamList();

    $driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $driver);

    DeliveryServer::factory()->create(['customer_id' => $list->customer_id, 'type' => 'smtp']);

    $leaver = Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'email' => 'leaver@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);
    Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'email' => 'stayer@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);

    // One-click unsubscribe: status flips in the same request, not "within 10
    // days". The DB column has second precision, so the reference instant is
    // rounded down before comparing.
    $before = now()->startOfSecond();

    $this->post(route('lists.public.unsubscribe.one_click', [
        'list_uid' => $list->uid,
        'subscriber_uid' => $leaver->uid,
    ]), ['List-Unsubscribe' => 'One-Click'])->assertOk();

    $leaver = $leaver->fresh();

    expect($leaver->status)->toBe(SubscriberStatus::Unsubscribed)
        ->and($leaver->unsubscribed_at)->not->toBeNull()
        ->and($leaver->unsubscribed_at->greaterThanOrEqualTo($before))->toBeTrue()
        // Statutory ceiling is 10 days; we are inside the same second.
        ->and($leaver->unsubscribed_at->diffInDays($before))->toBeLessThan(10);

    // The subsequent send skips them entirely.
    canspamCampaign($list);

    $this->artisan('campaigns:send')->assertSuccessful();

    $recipients = collect($driver->sent)
        ->map(fn (array $row): string => (string) $row['message']->firstRecipient())
        ->all();

    expect($recipients)->toBe(['stayer@example.com'])
        ->and(CampaignDeliveryLog::query()->where('subscriber_id', $leaver->id)->count())->toBe(0);
});

// ------------------------------------------ F-4.5-002 (c) honest headers

it('stamps honest From and Reply-To headers from the campaign', function (): void {
    $list = canspamList();

    $driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $driver);

    $server = DeliveryServer::factory()->create([
        'customer_id' => $list->customer_id,
        'type' => 'smtp',
        'from_email' => 'server@acme.test',
        'from_name' => 'Acme Servers',
        'reply_to_email' => 'server-replies@acme.test',
        'force_from' => false,
        'force_reply_to' => false,
    ]);

    Subscriber::factory()->create([
        'list_id' => $list->id,
        'customer_id' => $list->customer_id,
        'email' => 'reader@example.com',
        'status' => SubscriberStatus::Confirmed,
    ]);

    canspamCampaign($list);

    $this->artisan('campaigns:send')->assertSuccessful();

    expect($driver->sent)->toHaveCount(1);

    /** @var Message $message */
    $message = $driver->sent[0]['message'];

    expect($message->fromEmail)->toBe('news@acme.test')
        ->and($message->fromName)->toBe('Acme')
        ->and($message->replyTo)->toBe('replies@acme.test')
        // ...and the CAN-SPAM postal footer is really in the body.
        ->and($message->html)->toContain('Acme Inc.')
        ->and($message->html)->toContain('1 Acme Way')
        ->and($message->html)->toContain('Springfield');

    // The real RFC 5322 headers carry the campaign identity, not a forged one.
    $builder = app(\App\Sending\MimeBuilder::class);

    expect($builder->effectiveFrom($message, $server))->toBe(['news@acme.test', 'Acme'])
        ->and($builder->effectiveReplyTo($message, $server))->toBe('replies@acme.test');

    // Force-FROM / Force-REPLY-TO (F-2.9.2-027): the operator's verified
    // identity replaces the campaign's — still honest, never blank.
    $server->forceFill(['force_from' => true, 'force_reply_to' => true])->save();
    $server = $server->fresh();

    expect($builder->effectiveFrom($message, $server))->toBe(['server@acme.test', 'Acme Servers'])
        ->and($builder->effectiveReplyTo($message, $server))->toBe('server-replies@acme.test');

    $raw = $builder->build($message, $server)->toString();

    expect($raw)->toContain('server@acme.test')
        ->and($raw)->toContain('server-replies@acme.test');
});
