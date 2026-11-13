<?php

declare(strict_types=1);

use App\Models\CampaignTrackOpen;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Support\Stats\LiveCounter;

const CHROME_WIN_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

// F-1.7-001/006: pixel response + full log row + live counter.
it('serves the 1x1 gif and logs the open with parsed device data', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $subscriber = Subscriber::factory()->for($customer)->for($list, 'list')->create(['last_open_at' => null]);

    $response = $this->get(
        route('campaigns.track.open', ['campaign_uid' => 'camp-123', 'subscriber_uid' => $subscriber->uid]),
        ['User-Agent' => CHROME_WIN_UA],
    );

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // The body is a real GIF (spec: 1x1 transparent pixel).
    expect(substr($response->getContent(), 0, 6))->toBe('GIF89a');

    /** @var CampaignTrackOpen $open */
    $open = CampaignTrackOpen::query()->firstOrFail();
    expect($open->campaign_uid)->toBe('camp-123')
        ->and($open->subscriber_uid)->toBe($subscriber->uid)
        ->and($open->campaign_id)->toBeNull() // WP-11 backfills ids
        ->and($open->ip)->not->toBeNull()
        ->and($open->device)->toBe('desktop')
        ->and($open->os)->toBe('Windows')
        ->and($open->browser)->toBe('Chrome')
        ->and($open->country)->toBeNull() // GeoIP arrives with D-10
        ->and($open->created_at)->not->toBeNull();

    // Engagement timestamp + live counters (spec §4.6).
    expect($subscriber->refresh()->last_open_at)->not->toBeNull();

    $counter = app(LiveCounter::class);
    expect($counter->value('track:opens'))->toBe(1)
        ->and($counter->value('campaigns:camp-123:opens'))->toBe(1);
});
