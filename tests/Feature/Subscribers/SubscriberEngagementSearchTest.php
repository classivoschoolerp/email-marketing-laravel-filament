<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\SubscriberResource\Pages\ListSubscribers;
use App\Models\Campaign;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * F-2.2.4-003 — engagement search on the subscribers index, evaluated against
 * the WP-09 tracking tables. Fixture (all in one list of this tenant):
 *
 *   opener   — opened campaign A yesterday
 *   stale    — opened campaign A 90 days ago
 *   clicker  — opened AND clicked campaign A yesterday
 *   silent   — no tracking rows at all
 *   foreign  — an open row belonging to ANOTHER tenant's campaign
 */
beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();

    $this->opener = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'opener@x.example']);
    $this->stale = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'stale@x.example']);
    $this->clicker = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'clicker@x.example']);
    $this->silent = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'silent@x.example']);
    $this->foreign = Subscriber::factory()->for($this->list, 'list')->create(['email' => 'foreign@x.example']);

    $campaign = Campaign::factory()->for($this->customer)->create(['email_list_id' => $this->list->id]);
    $otherTenantCampaign = Campaign::factory()->create();

    $url = CampaignTrackUrl::create([
        'campaign_id' => $campaign->id,
        'campaign_uid' => $campaign->uid,
        'url' => 'https://example.test/offer',
        'hash' => str_repeat('h', 64),
    ]);

    $open = fn (Subscriber $s, Campaign $c, $at) => CampaignTrackOpen::create([
        'campaign_id' => $c->id,
        'campaign_uid' => $c->uid,
        'subscriber_uid' => $s->uid,
        'created_at' => $at,
    ]);

    $open($this->opener, $campaign, now()->subDay());
    $open($this->stale, $campaign, now()->subDays(90));
    $open($this->clicker, $campaign, now()->subDay());
    $open($this->foreign, $otherTenantCampaign, now()->subDay());

    CampaignTrackClick::create([
        'campaign_id' => $campaign->id,
        'campaign_uid' => $campaign->uid,
        'subscriber_uid' => $this->clicker->uid,
        'url_id' => $url->id,
        'created_at' => now()->subDay(),
    ]);

    $this->filtered = fn (string $mode, ?int $days = null) => Livewire::test(ListSubscribers::class)
        ->filterTable('engagement', ['mode' => $mode, 'within_days' => $days]);
});

it('filters subscribers who opened a campaign (F-2.2.4-003)', function (): void {
    // opener + stale + clicker; the foreign-tenant open never counts.
    ($this->filtered)('opened')
        ->assertCanSeeTableRecords([$this->opener, $this->stale, $this->clicker])
        ->assertCanNotSeeTableRecords([$this->silent, $this->foreign])
        ->assertCountTableRecords(3);
});

it('filters subscribers who did not open (F-2.2.4-003)', function (): void {
    ($this->filtered)('not_opened')
        ->assertCanSeeTableRecords([$this->silent, $this->foreign])
        ->assertCanNotSeeTableRecords([$this->opener, $this->stale, $this->clicker])
        ->assertCountTableRecords(2);
});

it('filters subscribers who clicked and who did not click (F-2.2.4-003)', function (): void {
    ($this->filtered)('clicked')
        ->assertCanSeeTableRecords([$this->clicker])
        ->assertCountTableRecords(1);

    ($this->filtered)('not_clicked')
        ->assertCanSeeTableRecords([$this->opener, $this->stale, $this->silent, $this->foreign])
        ->assertCanNotSeeTableRecords([$this->clicker])
        ->assertCountTableRecords(4);
});

it('bounds engagement to the last N days (F-2.2.4-003)', function (): void {
    // stale's only open is 90 days old, so a 30-day window drops it.
    ($this->filtered)('opened', 30)
        ->assertCanSeeTableRecords([$this->opener, $this->clicker])
        ->assertCanNotSeeTableRecords([$this->stale])
        ->assertCountTableRecords(2);

    ($this->filtered)('not_opened', 30)
        ->assertCanSeeTableRecords([$this->stale, $this->silent, $this->foreign])
        ->assertCountTableRecords(3);

    // A window wide enough to cover everything matches the unwindowed set.
    ($this->filtered)('opened', 365)->assertCountTableRecords(3);
});

it('leaves the table untouched when no engagement mode is chosen', function (): void {
    Livewire::test(ListSubscribers::class)
        ->filterTable('engagement', ['mode' => null, 'within_days' => 30])
        ->assertCountTableRecords(5);
});

it('never counts another tenant\'s tracking rows or subscribers', function (): void {
    // A subscriber of another tenant with a real open on their own campaign.
    $otherCustomer = Customer::factory()->create();
    $otherList = EmailList::factory()->for($otherCustomer)->create();
    $otherSubscriber = Subscriber::factory()->for($otherList, 'list')->create(['email' => 'other@y.example']);
    $otherCampaign = Campaign::factory()->for($otherCustomer)->create(['email_list_id' => $otherList->id]);

    CampaignTrackOpen::create([
        'campaign_id' => $otherCampaign->id,
        'campaign_uid' => $otherCampaign->uid,
        'subscriber_uid' => $otherSubscriber->uid,
        'created_at' => now()->subDay(),
    ]);

    ($this->filtered)('opened')
        ->assertCanNotSeeTableRecords([$otherSubscriber])
        ->assertCountTableRecords(3);
});
