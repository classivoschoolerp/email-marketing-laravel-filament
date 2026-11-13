<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\EmailListResource;
use App\Filament\Customer\Resources\EmailListResource\Pages\ListOverview;
use App\Filament\Customer\Resources\EmailListResource\Widgets\ListGrowthChart;
use App\Filament\Customer\Resources\EmailListResource\Widgets\ListSourceChart;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUrl;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Services\Lists\FieldValueService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->customer = Customer::factory()->create();
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);

    $this->list = EmailList::factory()->for($this->customer)->create();
});

it('renders the overview page with status counters and section headings', function (): void {
    Subscriber::factory()->for($this->list, 'list')->count(2)->create();
    Subscriber::factory()->for($this->list, 'list')->bounced('soft')->create();

    $this->get(EmailListResource::getUrl('overview', ['record' => $this->list], panel: 'customer'))
        ->assertOk()
        ->assertSee(__('subscribers.status.confirmed'))
        ->assertSee(__('lists.overview.campaigns_heading'))
        // No campaign has been sent to this list yet — say so, honestly.
        ->assertSee(__('lists.overview.campaigns_empty'));
});

// F-2.2.3-006 — recent campaigns sent to THIS list, numbers straight from
// CampaignReportService. Fixture: 3 delivered, 2 unique openers (3 hits),
// 1 unique clicker (2 hits).
it('lists recent campaigns sent to this list with their real stats', function (): void {
    $campaign = Campaign::factory()->for($this->customer)->create([
        'email_list_id' => $this->list->id,
        'name' => 'Spring blast',
        'status' => CampaignStatus::Sent,
        'send_started_at' => now()->subDays(2),
        'delivery_total' => 3,
    ]);

    $subscribers = Subscriber::factory()->for($this->list, 'list')->count(3)->create();

    foreach ($subscribers as $subscriber) {
        CampaignDeliveryLog::create([
            'customer_id' => $this->customer->id,
            'campaign_id' => $campaign->id,
            'subscriber_id' => $subscriber->id,
            'status' => CampaignDeliveryLog::STATUS_SENT,
        ]);
    }

    $url = CampaignTrackUrl::create([
        'campaign_id' => $campaign->id,
        'campaign_uid' => $campaign->uid,
        'url' => 'https://example.test/spring',
        'hash' => str_repeat('s', 64),
    ]);

    // 3 open hits from 2 distinct subscribers.
    foreach ([$subscribers[0], $subscribers[0], $subscribers[1]] as $subscriber) {
        CampaignTrackOpen::create([
            'campaign_id' => $campaign->id,
            'campaign_uid' => $campaign->uid,
            'subscriber_uid' => $subscriber->uid,
            'created_at' => now()->subDay(),
        ]);
    }

    // 2 click hits from 1 distinct subscriber.
    foreach ([$subscribers[0], $subscribers[0]] as $subscriber) {
        CampaignTrackClick::create([
            'campaign_id' => $campaign->id,
            'campaign_uid' => $campaign->uid,
            'subscriber_uid' => $subscriber->uid,
            'url_id' => $url->id,
            'created_at' => now()->subDay(),
        ]);
    }

    // Drafts, other lists and other tenants must never appear.
    Campaign::factory()->for($this->customer)->create([
        'email_list_id' => $this->list->id,
        'name' => 'Never sent draft',
        'status' => CampaignStatus::Draft,
    ]);
    $otherList = EmailList::factory()->for($this->customer)->create();
    Campaign::factory()->for($this->customer)->create([
        'email_list_id' => $otherList->id,
        'name' => 'Other list blast',
        'status' => CampaignStatus::Sent,
        'send_started_at' => now()->subDay(),
    ]);

    $page = Livewire::test(ListOverview::class, ['record' => $this->list->getRouteKey()])->instance();
    $rows = $page->recentCampaigns();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['campaign']->name)->toBe('Spring blast')
        ->and($rows[0]['delivered'])->toBe(3)
        ->and($rows[0]['opens'])->toBe(3)
        ->and($rows[0]['unique_opens'])->toBe(2)
        ->and($rows[0]['clicks'])->toBe(2)
        ->and($rows[0]['unique_clicks'])->toBe(1)
        // 2/3 and 1/3 of the delivered rows.
        ->and($rows[0]['open_rate'])->toBe(66.7)
        ->and($rows[0]['click_rate'])->toBe(33.3);

    $this->get(EmailListResource::getUrl('overview', ['record' => $this->list], panel: 'customer'))
        ->assertOk()
        ->assertSee('Spring blast')
        ->assertDontSee('Never sent draft')
        ->assertDontSee('Other list blast');
});

it('reports per-status counts including the hard/soft bounce split (F-2.2.3-001)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->count(3)->create();
    Subscriber::factory()->for($this->list, 'list')->unconfirmed()->create();
    Subscriber::factory()->for($this->list, 'list')->bounced('hard')->create();
    Subscriber::factory()->for($this->list, 'list')->bounced('soft')->create();

    expect($this->list->statusCounts())
        ->toMatchArray(['confirmed' => 3, 'unconfirmed' => 1, 'bounced' => 2])
        ->and($this->list->bounceTypeCounts())->toBe(['hard' => 1, 'soft' => 1]);
});

it('buckets growth daily, weekly and monthly (F-2.2.3-002)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->count(2)->create(['subscribed_at' => now()]);
    Subscriber::factory()->for($this->list, 'list')->create(['subscribed_at' => now()->subDays(2)]);
    Subscriber::factory()->for($this->list, 'list')->create(['subscribed_at' => now()->subMonths(2)]);

    $widget = new ListGrowthChart;
    $widget->record = $this->list;

    $widget->filter = 'daily';
    $daily = $widget->buckets();
    expect($daily->get(now()->format('Y-m-d')))->toBe(2)
        ->and($daily->get(now()->subDays(2)->format('Y-m-d')))->toBe(1)
        ->and($daily)->toHaveCount(30);

    $widget->filter = 'monthly';
    $monthly = $widget->buckets();
    expect($monthly->get(now()->format('Y-m')))->toBe(3)
        ->and($monthly->get(now()->subMonths(2)->format('Y-m')))->toBe(1)
        ->and($monthly)->toHaveCount(12);

    $widget->filter = 'weekly';
    expect($widget->buckets())->toHaveCount(12);
});

it('breaks subscribers down by source (F-2.2.3-003)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->count(2)->create(['source' => 'form']);
    Subscriber::factory()->for($this->list, 'list')->create(['source' => 'import']);
    Subscriber::factory()->for($this->list, 'list')->create(['source' => 'api']);

    $widget = new ListSourceChart;
    $widget->record = $this->list;

    expect($widget->sourceCounts())->toMatchArray([
        'form' => 2,
        'import' => 1,
        'api' => 1,
        'manual' => 0,
        'automation' => 0,
    ]);
});

it('breaks subscribers down by country from a Country field (F-2.2.3-004)', function (): void {
    $field = ListField::factory()->for($this->list, 'list')->type(ListFieldType::Country)->create();

    $service = app(FieldValueService::class);

    $us1 = Subscriber::factory()->for($this->list, 'list')->create();
    $us2 = Subscriber::factory()->for($this->list, 'list')->create();
    $de = Subscriber::factory()->for($this->list, 'list')->create();

    $service->set($us1, $field, 'US');
    $service->set($us2, $field, 'US');
    $service->set($de, $field, 'DE');

    $page = new ListOverview;
    $page->record = $this->list;

    $rows = $page->countryBreakdown();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['code'])->toBe('US')
        ->and($rows[0]['count'])->toBe(2)
        ->and($rows[0]['name'])->toBe('United States')
        ->and($rows[1]['code'])->toBe('DE');
});

it('merges recent activity into a timeline (F-2.2.3-007)', function (): void {
    Subscriber::factory()->for($this->list, 'list')->create([
        'email' => 'new@x.example',
        'subscribed_at' => now()->subMinutes(10),
        'confirmed_at' => now()->subMinutes(9),
    ]);
    Subscriber::factory()->for($this->list, 'list')->unsubscribed()->create([
        'email' => 'gone@x.example',
        'subscribed_at' => now()->subDay(),
        'unsubscribed_at' => now(),
    ]);

    $page = new ListOverview;
    $page->record = $this->list;

    $events = $page->activityTimeline();

    expect($events)->not->toBeEmpty()
        ->and($events[0]['type'])->toBe('unsubscribed')
        ->and($events[0]['email'])->toBe('gone@x.example')
        ->and(collect($events)->pluck('type')->unique()->values()->all())
        ->toContain('subscribed');
});

it('denies the overview, fields and segments pages to other tenants', function (): void {
    $foreignCustomer = Customer::factory()->create();
    $foreignList = EmailList::factory()->for($foreignCustomer)->create();

    $this->get(EmailListResource::getUrl('overview', ['record' => $foreignList], panel: 'customer'))
        ->assertNotFound();
    $this->get(EmailListResource::getUrl('fields', ['record' => $foreignList], panel: 'customer'))
        ->assertNotFound();
    $this->get(EmailListResource::getUrl('segments', ['record' => $foreignList], panel: 'customer'))
        ->assertNotFound();
});
