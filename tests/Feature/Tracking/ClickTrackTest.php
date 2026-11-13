<?php

declare(strict_types=1);

use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Tracking\UrlSigner;
use App\Support\Stats\LiveCounter;

beforeEach(function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $this->subscriber = Subscriber::factory()->for($customer)->for($list, 'list')->create(['last_click_at' => null]);

    $this->destination = 'https://example.com/promo?utm_source=newsletter&x=1';
    $this->hash = app(UrlSigner::class)->hash($this->destination);

    $this->url = CampaignTrackUrl::create([
        'campaign_uid' => 'camp-777',
        'url' => $this->destination,
        'hash' => $this->hash,
    ]);
});

// F-1.7-002/006: click → log row + 302 to the destination.
it('records the click and redirects to the destination URL', function (): void {
    $response = $this->get(route('campaigns.track.click', [
        'campaign_uid' => 'camp-777',
        'subscriber_uid' => $this->subscriber->uid,
        'url_hash' => $this->hash,
    ]), ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1']);

    $response->assertStatus(302);
    $response->assertRedirect($this->destination);

    /** @var CampaignTrackClick $click */
    $click = CampaignTrackClick::query()->firstOrFail();
    expect($click->campaign_uid)->toBe('camp-777')
        ->and($click->subscriber_uid)->toBe($this->subscriber->uid)
        ->and($click->url_id)->toBe($this->url->id)
        ->and($click->device)->toBe('mobile')
        ->and($click->os)->toBe('iOS')
        ->and($click->browser)->toBe('Safari');

    expect($this->subscriber->refresh()->last_click_at)->not->toBeNull();

    $counter = app(LiveCounter::class);
    expect($counter->value('track:clicks'))->toBe(1)
        ->and($counter->value('campaigns:camp-777:clicks'))->toBe(1);
});

// F-1.7-008: tampered or forged hashes never redirect.
it('rejects tampered hashes and forged rows', function (): void {
    // Tampered hash in the URL → no row match → 404.
    $this->get(route('campaigns.track.click', [
        'campaign_uid' => 'camp-777',
        'subscriber_uid' => $this->subscriber->uid,
        'url_hash' => strrev($this->hash),
    ]))->assertNotFound();

    // Forged row whose hash is not the HMAC of its URL → verify() fails.
    $forged = CampaignTrackUrl::create([
        'campaign_uid' => 'camp-777',
        'url' => 'https://evil.example/phish',
        'hash' => str_repeat('ab', 32),
    ]);

    $this->get(route('campaigns.track.click', [
        'campaign_uid' => 'camp-777',
        'subscriber_uid' => $this->subscriber->uid,
        'url_hash' => $forged->hash,
    ]))->assertNotFound();

    expect(CampaignTrackClick::query()->count())->toBe(0);
});

// The UrlSigner itself: deterministic, verifiable, tamper-evident.
it('signs and verifies URLs with HMAC and rejects tampering', function (): void {
    $signer = app(UrlSigner::class);

    $hash = $signer->hash('https://example.com/a');

    expect($hash)->toHaveLength(64)
        ->and($signer->hash('https://example.com/a'))->toBe($hash)
        ->and($signer->verify('https://example.com/a', $hash))->toBeTrue()
        // Different URL, flipped hash, truncated hash — all rejected.
        ->and($signer->verify('https://example.com/b', $hash))->toBeFalse()
        ->and($signer->verify('https://example.com/a', strrev($hash)))->toBeFalse()
        ->and($signer->verify('https://example.com/a', substr($hash, 0, 63)))->toBeFalse();
});
