<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\CurrentCustomer;

/**
 * F-2.2.10-007 — geo conditions on the platform's REAL recorded columns:
 * country / region / city from the geo columns stamped on tracking hits
 * (campaign_track_opens / campaign_track_clicks), timezone from
 * subscribers.timezone. No lookup is performed while segmenting.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->builder = app(SegmentQueryBuilder::class);

    $this->campaign = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);
    $this->foreignCampaign = Campaign::factory()->create();

    $this->casa = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'casa@x.example',
        'timezone' => 'Africa/Casablanca',
    ]);
    $this->rabat = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'rabat@x.example',
        'timezone' => 'Africa/Casablanca',
    ]);
    $this->paris = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'paris@x.example',
        'timezone' => 'Europe/Paris',
    ]);
    // Geo recorded, but on ANOTHER tenant's campaign — must never match.
    $this->leak = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'leak@x.example',
        'timezone' => null,
    ]);
    // No tracking hit at all, no timezone.
    $this->unknown = Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'unknown@x.example',
        'timezone' => null,
    ]);

    $open = fn (Subscriber $s, Campaign $c, array $geo) => CampaignTrackOpen::create([
        'campaign_id' => $c->id,
        'campaign_uid' => $c->uid,
        'subscriber_uid' => $s->uid,
        'created_at' => now()->subDay(),
    ] + $geo);

    $open($this->casa, $this->campaign, ['country' => 'Morocco', 'region' => 'Casablanca-Settat', 'city' => 'Casablanca']);
    $open($this->rabat, $this->campaign, ['country' => 'Morocco', 'region' => 'Rabat-Salé-Kénitra', 'city' => 'Rabat']);
    $open($this->leak, $this->foreignCampaign, ['country' => 'Morocco', 'region' => 'Casablanca-Settat', 'city' => 'Casablanca']);

    // paris@ is known only through a CLICK, proving both tracking tables count.
    $url = CampaignTrackUrl::create([
        'campaign_id' => $this->campaign->id,
        'campaign_uid' => $this->campaign->uid,
        'url' => 'https://example.test/p',
        'hash' => str_repeat('p', 64),
    ]);

    CampaignTrackClick::create([
        'campaign_id' => $this->campaign->id,
        'campaign_uid' => $this->campaign->uid,
        'subscriber_uid' => $this->paris->uid,
        'url_id' => $url->id,
        'country' => 'France',
        'region' => 'Île-de-France',
        'city' => 'Paris',
        'created_at' => now()->subDay(),
    ]);

    $this->emails = fn (string $key, string $operator, ?string $value = null): array => $this->builder
        ->build($this->list, 'all', [[
            'type' => 'geo',
            'field_key' => $key,
            'operator' => $operator,
            'value' => $value,
        ]])
        ->orderBy('email')
        ->pluck('email')
        ->all();
});

it('matches country, region and city from recorded tracking geo (F-2.2.10-007)', function (): void {
    $emails = $this->emails;

    // leak@'s Moroccan hit belongs to another tenant's campaign.
    expect($emails('country', 'is', 'Morocco'))->toBe(['casa@x.example', 'rabat@x.example'])
        ->and($emails('country', 'is', 'France'))->toBe(['paris@x.example'])
        ->and($emails('region', 'is', 'Casablanca-Settat'))->toBe(['casa@x.example'])
        ->and($emails('city', 'is', 'Rabat'))->toBe(['rabat@x.example'])
        ->and($emails('city', 'contains', 'ari'))->toBe(['paris@x.example'])
        ->and($emails('country', 'contains', 'oroc'))->toBe(['casa@x.example', 'rabat@x.example']);
});

it('treats negative geo operators as "never recorded there" (F-2.2.10-007)', function (): void {
    $emails = $this->emails;

    // Everyone without a Moroccan hit on THIS tenant's campaigns, including
    // subscribers with no recorded location at all.
    expect($emails('country', 'is_not', 'Morocco'))
        ->toBe(['leak@x.example', 'paris@x.example', 'unknown@x.example'])
        ->and($emails('country', 'not_contains', 'oroc'))
        ->toBe(['leak@x.example', 'paris@x.example', 'unknown@x.example'])
        // is_empty = no recorded location for this tenant at all.
        ->and($emails('country', 'is_empty'))->toBe(['leak@x.example', 'unknown@x.example'])
        ->and($emails('country', 'not_empty'))->toBe(['casa@x.example', 'paris@x.example', 'rabat@x.example']);
});

it('matches the subscriber timezone column (F-2.2.10-007)', function (): void {
    $emails = $this->emails;

    expect($emails('timezone', 'is', 'Africa/Casablanca'))->toBe(['casa@x.example', 'rabat@x.example'])
        ->and($emails('timezone', 'contains', 'Europe/'))->toBe(['paris@x.example'])
        ->and($emails('timezone', 'is_empty'))->toBe(['leak@x.example', 'unknown@x.example'])
        ->and($emails('timezone', 'not_empty'))
        ->toBe(['casa@x.example', 'paris@x.example', 'rabat@x.example']);
});

it('matches nothing for an unknown geo key (F-2.2.10-007)', function (): void {
    expect($this->builder->build($this->list, 'all', [[
        'type' => 'geo',
        'field_key' => 'continent',
        'operator' => 'is',
        'value' => 'Africa',
    ]])->pluck('email')->all())->toBe([]);
});

it('evaluates a saved geo segment and never leaks another tenant (F-2.2.10-007)', function (): void {
    $segment = ListSegment::factory()->for($this->list, 'list')->create(['match_type' => 'all']);

    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'geo',
        'field_key' => 'country',
        'operator' => 'is',
        'value' => 'Morocco',
        'sort_order' => 0,
    ]);

    expect($this->builder->subscribers($segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['casa@x.example', 'rabat@x.example'])
        ->and($this->builder->count($segment))->toBe(2);
});
