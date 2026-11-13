<?php

declare(strict_types=1);

use App\Models\Campaign;
use App\Models\CampaignTrackOpen;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\CurrentCustomer;

/**
 * F-2.2.10-006 — engagement conditions: "opened at least N times" and
 * "no opens in the last N days", evaluated inside the same subscriber query
 * as every other condition family.
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
    $this->builder = app(SegmentQueryBuilder::class);

    $this->campaignA = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);
    $this->campaignB = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);
    $this->foreignCampaign = Campaign::factory()->create(); // another tenant entirely

    // heavy@   3 recent opens of campaign A
    // stale@   1 open of campaign A, 60 days ago
    // mixed@   1 recent open of A + 1 recent open of B
    // foreign@ 1 recent open, but of ANOTHER tenant's campaign
    // silent@  never opened anything
    $this->heavy = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'heavy@x.example']);
    $this->stale = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'stale@x.example']);
    $this->mixed = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'mixed@x.example']);
    $this->foreign = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'foreign@x.example']);
    $this->silent = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'silent@x.example']);

    $open = fn (Subscriber $s, Campaign $c, $at) => CampaignTrackOpen::create([
        'campaign_id' => $c->id,
        'campaign_uid' => $c->uid,
        'subscriber_uid' => $s->uid,
        'created_at' => $at,
    ]);

    $open($this->heavy, $this->campaignA, now()->subDay());
    $open($this->heavy, $this->campaignA, now()->subDays(2));
    $open($this->heavy, $this->campaignA, now()->subDays(3));
    $open($this->stale, $this->campaignA, now()->subDays(60));
    $open($this->mixed, $this->campaignA, now()->subDay());
    $open($this->mixed, $this->campaignB, now()->subDays(2));
    $open($this->foreign, $this->foreignCampaign, now()->subDay());

    $this->emails = fn (string $operator, ?string $value, array $settings = []): array => $this->builder
        ->build($this->list, 'all', [[
            'type' => 'engagement',
            'operator' => $operator,
            'value' => $value,
            'settings' => $settings,
        ]])
        ->orderBy('email')
        ->pluck('email')
        ->all();
});

it('matches subscribers who opened at least N times (F-2.2.10-006)', function (): void {
    $emails = $this->emails;

    // heavy = 3 opens, mixed = 2, stale = 1 (old but still an open),
    // foreign's open belongs to another tenant and never counts.
    expect($emails('opened_at_least', '1'))->toBe(['heavy@x.example', 'mixed@x.example', 'stale@x.example'])
        ->and($emails('opened_at_least', '2'))->toBe(['heavy@x.example', 'mixed@x.example'])
        ->and($emails('opened_at_least', '3'))->toBe(['heavy@x.example'])
        ->and($emails('opened_at_least', '4'))->toBe([])
        // "At least 0 times" would be everybody: clamped to 1, never widened.
        ->and($emails('opened_at_least', '0'))->toBe(['heavy@x.example', 'mixed@x.example', 'stale@x.example']);
});

it('counts opens per campaign and inside a time window (F-2.2.10-006)', function (): void {
    $emails = $this->emails;

    // Campaign A alone: heavy 3, stale 1, mixed 1.
    expect($emails('opened_at_least', '2', ['campaign_id' => $this->campaignA->id]))->toBe(['heavy@x.example'])
        ->and($emails('opened_at_least', '1', ['campaign_id' => $this->campaignB->id]))->toBe(['mixed@x.example'])
        // A 30-day window drops stale@'s 60-day-old open.
        ->and($emails('opened_at_least', '1', ['within_days' => 30]))->toBe(['heavy@x.example', 'mixed@x.example'])
        ->and($emails('opened_at_least', '3', ['within_days' => 30]))->toBe(['heavy@x.example']);
});

it('matches subscribers with no opens in the last N days (F-2.2.10-006)', function (): void {
    $emails = $this->emails;

    // 30 days: stale@ (60-day-old open), foreign@ (other tenant), silent@ (none).
    expect($emails('no_opens_in_days', '30'))->toBe(['foreign@x.example', 'silent@x.example', 'stale@x.example'])
        // A 90-day window covers stale@'s open, so only the two never-openers remain.
        ->and($emails('no_opens_in_days', '90'))->toBe(['foreign@x.example', 'silent@x.example'])
        // 0 days = "no opens at all, ever".
        ->and($emails('no_opens_in_days', '0'))->toBe(['foreign@x.example', 'silent@x.example'])
        // Scoped to one campaign: everyone who never opened campaign B.
        ->and($emails('no_opens_in_days', '90', ['campaign_id' => $this->campaignB->id]))
        ->toBe(['foreign@x.example', 'heavy@x.example', 'silent@x.example', 'stale@x.example']);
});

it('matches nothing for an unknown or cross-tenant campaign in both directions (D-90, F-2.2.10-006)', function (): void {
    $emails = $this->emails;

    expect($emails('opened_at_least', '1', ['campaign_id' => $this->foreignCampaign->id]))->toBe([])
        ->and($emails('no_opens_in_days', '30', ['campaign_id' => $this->foreignCampaign->id]))->toBe([])
        ->and($emails('opened_at_least', '1', ['campaign_id' => 999999]))->toBe([])
        ->and($emails('no_opens_in_days', '30', ['campaign_id' => 999999]))->toBe([]);
});

it('evaluates a saved engagement segment through its stored conditions (F-2.2.10-006)', function (): void {
    $segment = ListSegment::factory()->for($this->list, 'list')->create(['match_type' => 'all']);

    $segment->conditions()->create([
        'customer_id' => $this->customer->id,
        'type' => 'engagement',
        'operator' => 'opened_at_least',
        'value' => '2',
        'sort_order' => 0,
    ]);

    expect($this->builder->subscribers($segment)->orderBy('email')->pluck('email')->all())
        ->toBe(['heavy@x.example', 'mixed@x.example'])
        ->and($this->builder->count($segment))->toBe(2);
});
