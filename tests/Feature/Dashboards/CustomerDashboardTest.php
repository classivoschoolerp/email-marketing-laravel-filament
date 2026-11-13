<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Filament\Customer\Widgets\AccountCountersWidget;
use App\Filament\Customer\Widgets\AnnouncementsWidget;
use App\Filament\Customer\Widgets\EngagementChart;
use App\Filament\Customer\Widgets\QuickActionsWidget;
use App\Filament\Customer\Widgets\QuotaOverviewWidget;
use App\Filament\Customer\Widgets\RecentActivityWidget;
use App\Filament\Customer\Widgets\RecentCampaignsTable;
use App\Filament\Customer\Widgets\SubscriberGrowthChart;
use App\Models\Announcement;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignStatsDaily;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\EmailTemplate;
use App\Models\ImportJob;
use App\Models\Subscriber;
use App\Services\Quota\QuotaUsageService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Livewire\Livewire;

// WP-18 — §2.1 customer dashboard: counters, quota widgets, charts, recent
// campaigns, activity feed, announcements and quick actions, with exact
// numbers from seeded fixtures and tenant isolation throughout.

beforeEach(function (): void {
    $this->customer = Customer::factory()->create(['company' => 'Acme Ltd']);
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

/** Seed a known object landscape for the tenant + noise for another tenant. */
function wp18_seed_counters(Customer $customer): void
{
    $list = EmailList::factory()->create(['customer_id' => $customer->id]);
    EmailList::factory()->create(['customer_id' => $customer->id]);

    Subscriber::factory()->count(3)->create(['list_id' => $list->id, 'customer_id' => $customer->id]);
    Subscriber::factory()->unconfirmed()->create(['list_id' => $list->id, 'customer_id' => $customer->id]);
    Subscriber::factory()->unsubscribed()->count(2)->create(['list_id' => $list->id, 'customer_id' => $customer->id]);
    Subscriber::factory()->bounced()->create(['list_id' => $list->id, 'customer_id' => $customer->id]);

    Campaign::factory()->sent()->count(2)->create(['customer_id' => $customer->id, 'email_list_id' => $list->id]);
    Campaign::factory()->scheduled()->create(['customer_id' => $customer->id, 'email_list_id' => $list->id]);
    Campaign::factory()->create([
        'customer_id' => $customer->id,
        'email_list_id' => $list->id,
        'status' => CampaignStatus::PendingApproval,
    ]);
    Campaign::factory()->create(['customer_id' => $customer->id, 'email_list_id' => $list->id]); // draft

    EmailTemplate::factory()->count(3)->create(['customer_id' => $customer->id]);
    DeliveryServer::factory()->create(['customer_id' => $customer->id]);

    // Another tenant's data must never bleed into the counters.
    $other = Customer::factory()->create();
    $otherList = EmailList::factory()->create(['customer_id' => $other->id]);
    Subscriber::factory()->count(5)->create(['list_id' => $otherList->id, 'customer_id' => $other->id]);
    Campaign::factory()->sent()->create(['customer_id' => $other->id, 'email_list_id' => $otherList->id]);
    EmailTemplate::factory()->create(['customer_id' => $other->id]);
    DeliveryServer::factory()->create(['customer_id' => $other->id]);
}

// F-2.1-001 — counter widgets with the exact seeded numbers.
it('shows the exact account counters', function (): void {
    wp18_seed_counters($this->customer);

    $widget = new AccountCountersWidget;
    $stats = (fn (): array => $this->getStats())->call($widget);

    $values = collect($stats)->map(fn ($stat) => $stat->getValue())->values()->all();

    expect($values)->toBe(['2', '7', '2', '2', '3', '1']);

    // The subscribers split renders active (confirmed+unconfirmed) 4,
    // unsubscribed 2, bounced 1.
    expect((string) $stats[1]->getDescription())
        ->toContain('4')->toContain('2')->toContain('1');
});

// F-2.1-002 — quota widget: hourly/daily/monthly usage + subscriber quota +
// storage from the customer's own directories.
it('shows sending quota usage, subscriber quota and storage', function (): void {
    Storage::fake('public');
    Storage::fake('local');

    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $campaign = Campaign::factory()->sent()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    $log = function (int $i, $at, string $status = CampaignDeliveryLog::STATUS_SENT) use ($list, $campaign): void {
        $subscriber = Subscriber::factory()->create(['list_id' => $list->id, 'customer_id' => $this->customer->id]);

        CampaignDeliveryLog::query()->create([
            'campaign_id' => $campaign->id, 'subscriber_id' => $subscriber->id,
            'status' => $status, 'created_at' => $at,
        ]);
    };

    // 3 delivered this hour, 2 delivered yesterday (this month), 1 failed (ignored).
    $log(1, now()->startOfHour()->addMinute());
    $log(2, now()->startOfHour()->addMinutes(2));
    $log(3, now()->startOfHour()->addMinutes(3));
    $log(4, now()->subDay());
    $log(5, now()->subDay()->addMinute());
    $log(6, now(), CampaignDeliveryLog::STATUS_FAILED);

    Storage::disk('public')->put('customers/'.$this->customer->uid.'/campaigns/a.png', str_repeat('x', 2048));
    Storage::disk('local')->put('customers/'.$this->customer->uid.'/exports/e.csv', str_repeat('y', 1024));
    Storage::disk('public')->put('customers/other-uid/campaigns/noise.png', str_repeat('z', 9999));

    $service = app(QuotaUsageService::class);
    $usage = $service->sendingUsage($this->customer);

    // Windows may nest (yesterday is in neither day nor hour; the hour's 3
    // are also today's 3 and part of the month's 5).
    expect($usage['hourly']['used'])->toBe(3)
        ->and($usage['daily']['used'])->toBe(3)
        ->and($usage['monthly']['used'])->toBe(now()->day === 1 ? 3 : 5)
        ->and($usage['hourly']['limit'])->toBe(10_000)
        ->and($usage['hourly']['resets_at']->toIso8601String())
        ->toBe(now()->startOfHour()->addHour()->toIso8601String());

    expect($service->storageBytes($this->customer))->toBe(3072);

    Livewire::test(QuotaOverviewWidget::class)
        ->assertSee(__('dashboards.customer.quota.heading'))
        ->assertSee('10,000')
        ->assertSee('3 KB');
});

// F-2.1-003 — subscriber growth chart buckets with a selectable range.
it('buckets subscriber growth by day with exact counts', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);

    Subscriber::factory()->count(2)->create([
        'list_id' => $list->id, 'customer_id' => $this->customer->id, 'subscribed_at' => now(),
    ]);
    Subscriber::factory()->create([
        'list_id' => $list->id, 'customer_id' => $this->customer->id, 'subscribed_at' => now()->subDays(3),
    ]);
    Subscriber::factory()->create([
        'list_id' => $list->id, 'customer_id' => $this->customer->id, 'subscribed_at' => now()->subDays(90),
    ]); // outside the 30-day window

    $widget = new SubscriberGrowthChart;
    $buckets = $widget->buckets();

    expect($buckets)->toHaveCount(30)
        ->and($buckets[now()->format('Y-m-d')])->toBe(2)
        ->and($buckets[now()->subDays(3)->format('Y-m-d')])->toBe(1)
        ->and($buckets->sum())->toBe(3);

    $widget->filter = 'monthly';

    expect($widget->buckets())->toHaveCount(12)
        ->and($widget->buckets()[now()->format('Y-m')])->toBe(3);
});

// F-2.1-004 — sends/opens/clicks last 30 days from the stats rollup.
it('charts sends, opens and clicks from the tenant rollup only', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $campaign = Campaign::factory()->sent()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    CampaignStatsDaily::query()->create([
        'campaign_id' => $campaign->id, 'day' => now()->startOfDay(),
        'sent' => 5, 'opens' => 4, 'clicks' => 3,
    ]);
    CampaignStatsDaily::query()->create([
        'campaign_id' => $campaign->id, 'day' => now()->subDay()->startOfDay(),
        'sent' => 2, 'opens' => 1, 'clicks' => 0,
    ]);

    // Foreign tenant rollup rows are excluded.
    $foreign = Campaign::factory()->sent()->create();
    CampaignStatsDaily::query()->create([
        'campaign_id' => $foreign->id, 'day' => now()->startOfDay(), 'sent' => 99, 'opens' => 99, 'clicks' => 99,
    ]);

    $series = (new EngagementChart)->series();
    $today = $series[now()->format('Y-m-d')];

    expect($series)->toHaveCount(30)
        ->and($today)->toBe(['sent' => 5, 'opens' => 4, 'clicks' => 3])
        ->and(array_sum(array_column($series, 'sent')))->toBe(7);
});

// F-2.1-005 — recent campaigns table with mini stats.
it('lists recent campaigns with delivered/opens/clicks mini stats', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $campaign = Campaign::factory()->sent()->create([
        'customer_id' => $this->customer->id,
        'email_list_id' => $list->id,
        'name' => 'Spring Newsletter',
        'delivery_sent' => 123,
    ]);

    CampaignStatsDaily::query()->create([
        'campaign_id' => $campaign->id, 'day' => now()->startOfDay(), 'sent' => 123, 'opens' => 45, 'clicks' => 6,
    ]);

    Livewire::test(RecentCampaignsTable::class)
        ->assertSee('Spring Newsletter')
        ->assertSee('123')
        ->assertSee('45')
        ->assertSee('6');
});

// F-2.1-006 — recent activity feed merges subscriber/campaign/import events.
it('merges account events into the activity feed, newest first', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Main list']);

    Subscriber::factory()->create([
        'list_id' => $list->id, 'customer_id' => $this->customer->id,
        'email' => 'new@example.test', 'subscribed_at' => now()->subMinutes(5),
    ]);
    Subscriber::factory()->unsubscribed()->create([
        'list_id' => $list->id, 'customer_id' => $this->customer->id,
        'email' => 'gone@example.test', 'subscribed_at' => now()->subDays(2),
        'unsubscribed_at' => now()->subHour(),
    ]);
    Campaign::factory()->sent()->create([
        'customer_id' => $this->customer->id, 'email_list_id' => $list->id,
        'name' => 'Weekly digest', 'send_finished_at' => now()->subMinutes(30),
    ]);
    ImportJob::query()->create([
        'customer_id' => $this->customer->id, 'list_id' => $list->id,
        'source' => 'csv', 'status' => ImportJob::STATUS_COMPLETED, 'finished_at' => now()->subMinutes(2),
    ]);

    $events = (new RecentActivityWidget)->events();

    expect($events[0]['type'])->toBe('import_finished')
        ->and(collect($events)->pluck('type')->all())
        ->toContain('subscribed', 'unsubscribed', 'campaign_sent');

    Livewire::test(RecentActivityWidget::class)
        ->assertSee('new@example.test')
        ->assertSee('Weekly digest');
});

// F-2.1-010 — announcements: active-window display + per-customer dismissal.
it('shows active announcements and lets the customer dismiss them', function (): void {
    $active = Announcement::query()->create(['title' => 'Maintenance window', 'body' => '<p>Sunday 02:00 UTC</p>']);
    Announcement::query()->create(['title' => 'Retired notice', 'body' => '<p>x</p>', 'active' => false]);
    Announcement::query()->create([
        'title' => 'Expired notice', 'body' => '<p>x</p>', 'ends_at' => now()->subDay(),
    ]);
    Announcement::query()->create([
        'title' => 'Future notice', 'body' => '<p>x</p>', 'starts_at' => now()->addDay(),
    ]);

    Livewire::test(AnnouncementsWidget::class)
        ->assertSee('Maintenance window')
        ->assertDontSee('Retired notice')
        ->assertDontSee('Expired notice')
        ->assertDontSee('Future notice')
        ->call('dismiss', $active->id)
        ->assertDontSee('Maintenance window');

    expect($active->dismissals()->where('customer_id', $this->customer->id)->exists())->toBeTrue();

    // Dismissal is per customer — another customer still sees it.
    $other = Customer::factory()->create();
    expect(Announcement::query()->visibleTo($other)->pluck('title')->all())->toBe(['Maintenance window']);
});

// F-2.1-011 — quick actions link to create-campaign/create-list/import.
it('renders the quick actions with their target urls', function (): void {
    EmailList::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(QuickActionsWidget::class)
        ->assertSee(__('dashboards.customer.quick_actions.create_campaign'))
        ->assertSee(__('dashboards.customer.quick_actions.create_list'))
        ->assertSee(__('dashboards.customer.quick_actions.import_subscribers'))
        ->assertSee('/customer/campaigns/create')
        ->assertSee('/customer/lists/create')
        ->assertSee('/import');
});

// §2.1 — the dashboard page itself renders for a customer…
it('renders the dashboard page with the WP-18 widgets', function (): void {
    $this->get('/customer')
        ->assertOk()
        ->assertSee(__('dashboards.customer.quota.heading'))
        ->assertSee(__('dashboards.customer.quick_actions.heading'))
        ->assertSee(__('dashboards.customer.activity.heading'));
});

// …and never for guests or the admin guard (cross-guard denial).
it('denies the customer dashboard to guests', function (): void {
    auth('customer')->logout();
    app(CurrentCustomer::class)->set(null);

    $this->get('/customer')->assertRedirect();
});
