<?php

declare(strict_types=1);

use App\Filament\Customer\Resources\CampaignResource\Pages\EditCampaign;
use App\Models\Campaign;
use App\Models\CampaignFilter;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Campaigns\CampaignRecipients;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * §2.3.7 campaign filters — F-2.3.7-001/002/003/004.
 *
 * Fixture (5 subscribers on one list, one earlier campaign "past"):
 *   s1  opened past, clicked link A
 *   s2  opened past, clicked link B
 *   s3  opened past, no click
 *   s4  did NOT open past
 *   s5  did NOT open past
 * ⇒ opened = 3, not opened = 2, clicked = 2, clicked link A = 1.
 */

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    $this->past = Campaign::factory()->sent()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'Past campaign',
        'send_started_at' => now()->subWeek(),
    ]);

    $this->campaign = Campaign::factory()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
        'name' => 'New campaign',
    ]);

    $this->subs = collect();

    for ($i = 1; $i <= 5; $i++) {
        $this->subs->push(Subscriber::factory()->create([
            'customer_id' => $this->customer->id,
            'list_id' => $this->list->id,
            'email' => 'f'.$i.'@fixture.test',
        ]));
    }

    $this->linkA = CampaignTrackUrl::query()->create([
        'campaign_id' => $this->past->id,
        'campaign_uid' => $this->past->uid,
        'url' => 'https://example.com/a',
        'hash' => str_repeat('a', 64),
    ]);

    $this->linkB = CampaignTrackUrl::query()->create([
        'campaign_id' => $this->past->id,
        'campaign_uid' => $this->past->uid,
        'url' => 'https://example.com/b',
        'hash' => str_repeat('b', 64),
    ]);

    foreach ([0, 1, 2] as $i) {
        CampaignTrackOpen::query()->create([
            'campaign_id' => $this->past->id,
            'campaign_uid' => $this->past->uid,
            'subscriber_uid' => $this->subs[$i]->uid,
            'created_at' => now()->subDays(3),
        ]);
    }

    CampaignTrackClick::query()->create([
        'campaign_id' => $this->past->id,
        'campaign_uid' => $this->past->uid,
        'subscriber_uid' => $this->subs[0]->uid,
        'url_id' => $this->linkA->id,
        'created_at' => now()->subDays(3),
    ]);

    CampaignTrackClick::query()->create([
        'campaign_id' => $this->past->id,
        'campaign_uid' => $this->past->uid,
        'subscriber_uid' => $this->subs[1]->uid,
        'url_id' => $this->linkB->id,
        'created_at' => now()->subDays(3),
    ]);

    $this->recipients = app(CampaignRecipients::class);
});

function wp35_filter(Campaign $campaign, string $type, ?int $targetId, string $mode = CampaignFilter::MODE_INCLUDE, ?int $urlId = null): CampaignFilter
{
    return $campaign->filters()->create([
        'type' => $type,
        'mode' => $mode,
        'target_campaign_id' => $targetId,
        'campaign_track_url_id' => $urlId,
        'position' => $campaign->filters()->count(),
    ]);
}

// Baseline: no filters at all.
it('counts every confirmed subscriber without filters', function (): void {
    expect($this->recipients->count($this->campaign))->toBe(5);
});

// F-2.3.7-001 — opened / did not open campaign X.
it('filters on opened and did-not-open campaign x', function (): void {
    wp35_filter($this->campaign, CampaignFilter::TYPE_OPENED, $this->past->id);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(3);

    $this->campaign->filters()->delete();
    $this->campaign->unsetRelation('filters');

    wp35_filter($this->campaign, CampaignFilter::TYPE_NOT_OPENED, $this->past->id);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(2);
});

// F-2.3.7-002 — clicked / did not click, and a SPECIFIC link.
it('filters on clicked, did-not-click and a specific link', function (): void {
    wp35_filter($this->campaign, CampaignFilter::TYPE_CLICKED, $this->past->id);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(2);

    $this->campaign->filters()->delete();
    $this->campaign->unsetRelation('filters');

    wp35_filter($this->campaign, CampaignFilter::TYPE_NOT_CLICKED, $this->past->id);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(3);

    $this->campaign->filters()->delete();
    $this->campaign->unsetRelation('filters');

    wp35_filter($this->campaign, CampaignFilter::TYPE_CLICKED, $this->past->id, urlId: $this->linkA->id);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(1);

    $this->campaign->filters()->delete();
    $this->campaign->unsetRelation('filters');

    wp35_filter($this->campaign, CampaignFilter::TYPE_NOT_CLICKED, $this->past->id, urlId: $this->linkA->id);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(4);
});

// F-2.3.7-003 — multiple includes combined with ALL / ANY.
it('combines multiple include filters with all and any', function (): void {
    wp35_filter($this->campaign, CampaignFilter::TYPE_OPENED, $this->past->id);
    wp35_filter($this->campaign, CampaignFilter::TYPE_CLICKED, $this->past->id, urlId: $this->linkA->id);

    // ALL: opened AND clicked link A ⇒ only s1.
    $this->campaign->update(['filter_match' => CampaignFilter::MATCH_ALL]);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(1);

    // ANY: opened OR clicked link A ⇒ the three openers.
    $this->campaign->update(['filter_match' => CampaignFilter::MATCH_ANY]);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(3);
});

// F-2.3.7-003 — exclude subtracts, regardless of the include combination.
it('subtracts exclude filters', function (): void {
    wp35_filter($this->campaign, CampaignFilter::TYPE_OPENED, $this->past->id);
    wp35_filter($this->campaign, CampaignFilter::TYPE_CLICKED, $this->past->id, CampaignFilter::MODE_EXCLUDE);

    // opened (3) minus anyone who clicked (2) ⇒ s3 only.
    expect($this->recipients->count($this->campaign->fresh()))->toBe(1);
});

it('applies an exclude filter with no includes at all', function (): void {
    wp35_filter($this->campaign, CampaignFilter::TYPE_OPENED, $this->past->id, CampaignFilter::MODE_EXCLUDE);

    expect($this->recipients->count($this->campaign->fresh()))->toBe(2);
});

// D-90: a broken or cross-tenant target matches NOTHING in both directions.
it('matches nothing for an unknown or cross-tenant target campaign', function (): void {
    wp35_filter($this->campaign, CampaignFilter::TYPE_OPENED, 999999);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(0);

    $this->campaign->filters()->delete();
    $this->campaign->unsetRelation('filters');

    // "Did not open" a campaign that does not exist must also match nothing —
    // widening the audience on a broken filter would mail untargeted people.
    wp35_filter($this->campaign, CampaignFilter::TYPE_NOT_OPENED, 999999);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(0);

    $this->campaign->filters()->delete();
    $this->campaign->unsetRelation('filters');

    $other = Customer::factory()->create();
    $foreign = app(CurrentCustomer::class)->runAs($other, function () use ($other): Campaign {
        $list = EmailList::factory()->create(['customer_id' => $other->id]);

        return Campaign::factory()->sent()->create([
            'customer_id' => $other->id,
            'email_list_id' => $list->id,
        ]);
    });

    wp35_filter($this->campaign, CampaignFilter::TYPE_OPENED, $foreign->id);
    expect($this->recipients->count($this->campaign->fresh()))->toBe(0);
});

it('matches nothing when the chosen link belongs to another campaign', function (): void {
    $otherCampaign = Campaign::factory()->sent()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $this->list->id,
    ]);

    wp35_filter($this->campaign, CampaignFilter::TYPE_CLICKED, $otherCampaign->id, urlId: $this->linkA->id);

    expect($this->recipients->count($this->campaign->fresh()))->toBe(0);
});

// F-2.3.7-004 — the count preview and the send path share the SAME query.
it('previews the recipient count through the same choke point the send uses', function (): void {
    wp35_filter($this->campaign, CampaignFilter::TYPE_OPENED, $this->past->id);

    $campaign = $this->campaign->fresh();

    $query = $this->recipients->query($campaign);
    $emails = $query->pluck('subscribers.email')->sort()->values()->all();

    expect($this->recipients->count($campaign))->toBe(3)
        ->and($emails)->toBe(['f1@fixture.test', 'f2@fixture.test', 'f3@fixture.test']);
});

// The wizard's live preview reads the UNSAVED filter state.
it('previews the count from unsaved wizard state', function (): void {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'filter_match' => CampaignFilter::MATCH_ALL,
            'filters' => [
                'new' => [
                    'type' => CampaignFilter::TYPE_NOT_OPENED,
                    'mode' => CampaignFilter::MODE_INCLUDE,
                    'target_campaign_id' => $this->past->id,
                    'campaign_track_url_id' => null,
                ],
            ],
        ])
        ->assertSee(__('campaigns.confirmation.recipients_estimate', ['count' => '2']));
});

// Filters persist through the wizard and are re-fenced to the tenant.
it('saves filters from the wizard', function (): void {
    Livewire::test(EditCampaign::class, ['record' => $this->campaign->getRouteKey()])
        ->fillForm([
            'name' => 'New campaign',
            'email_list_id' => $this->list->id,
            'filter_match' => CampaignFilter::MATCH_ANY,
            'filters' => [
                'new' => [
                    'type' => CampaignFilter::TYPE_CLICKED,
                    'mode' => CampaignFilter::MODE_EXCLUDE,
                    'target_campaign_id' => $this->past->id,
                    'campaign_track_url_id' => $this->linkB->id,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $filter = $this->campaign->fresh()->filters()->firstOrFail();

    expect($filter->type)->toBe(CampaignFilter::TYPE_CLICKED)
        ->and($filter->mode)->toBe(CampaignFilter::MODE_EXCLUDE)
        ->and((int) $filter->target_campaign_id)->toBe($this->past->id)
        ->and((int) $filter->campaign_track_url_id)->toBe($this->linkB->id)
        ->and($this->campaign->fresh()->filter_match)->toBe(CampaignFilter::MATCH_ANY);
});
