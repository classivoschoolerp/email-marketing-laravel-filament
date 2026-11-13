<?php

declare(strict_types=1);

use App\Filament\Customer\Pages\UsageAndQuotas;
use App\Filament\Customer\Widgets\MonthlySendsChart;
use App\Models\Campaign;
use App\Models\CampaignStatsDaily;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerLimit;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\SendingDomain;
use App\Models\Subscriber;
use App\Services\Quota\QuotaUsageService;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

// WP-18 — §2.15.5 usage & quotas page: plan summary, quota bars + reset
// times, counts vs limits, storage, historical chart and the upgrade CTA
// (billing runs parallel — plain-text fallback when the route is absent).

beforeEach(function (): void {
    $this->group = CustomerGroup::factory()->create(['name' => 'Gold plan']);
    $this->customer = Customer::factory()->create(['customer_group_id' => $this->group->id]);
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

// F-2.15.5-001/002/003/004/005 — the page renders group, quotas and counts.
it('renders the usage page with the exact numbers', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    Storage::disk('local')->put('customers/'.$this->customer->uid.'/exports/x.csv', str_repeat('a', 4096));

    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    EmailList::factory()->create(['customer_id' => $this->customer->id]);
    Subscriber::factory()->count(3)->create(['list_id' => $list->id, 'customer_id' => $this->customer->id]);
    Campaign::factory()->sent()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);
    DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);
    SendingDomain::factory()->create(['customer_id' => $this->customer->id]);

    $this->get(UsageAndQuotas::getUrl(panel: 'customer'))
        ->assertOk()
        ->assertSee('Gold plan')                                    // F-2.15.5-001
        ->assertSee(__('dashboards.usage.sending_heading'))         // F-2.15.5-002
        ->assertSee(__('dashboards.usage.windows.hourly'))
        ->assertSee('10,000')                                       // sends_per_hour default limit
        ->assertSee(__('dashboards.usage.objects.subscribers'))     // F-2.15.5-003
        ->assertSee(__('dashboards.usage.objects.lists'))           // F-2.15.5-004
        ->assertSee(__('dashboards.usage.objects.sending_domains'))
        ->assertSee('4 KB');                                        // F-2.15.5-005
});

// F-2.15.5-002 — reset times: next hour/day/month boundaries (UTC).
it('computes the quota reset boundaries', function (): void {
    $usage = app(QuotaUsageService::class)->sendingUsage($this->customer);

    expect($usage['hourly']['resets_at']->toIso8601String())
        ->toBe(now()->startOfHour()->addHour()->toIso8601String())
        ->and($usage['daily']['resets_at']->toIso8601String())
        ->toBe(now()->startOfDay()->addDay()->toIso8601String())
        ->and($usage['monthly']['resets_at']->toIso8601String())
        ->toBe(now()->startOfMonth()->addMonth()->toIso8601String());
});

// F-2.15.5-003/004 — counts vs effective limits (customer override wins).
it('reports object usage against effective limits', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    Subscriber::factory()->count(2)->create(['list_id' => $list->id, 'customer_id' => $this->customer->id]);

    CustomerLimit::query()->create(['customer_id' => $this->customer->id, 'key' => 'max_lists', 'value' => 3]);

    $objects = app(QuotaUsageService::class)->objectUsage($this->customer);

    expect($objects['lists'])->toBe(['used' => 1, 'limit' => 3])
        ->and($objects['subscribers']['used'])->toBe(2)
        ->and($objects['subscribers']['limit'])->toBe(100_000)
        ->and($objects['campaigns']['used'])->toBe(0)
        ->and($objects['delivery_servers']['used'])->toBe(0)
        ->and($objects['sending_domains']['used'])->toBe(0);
});

// F-2.15.5-006 — historical usage chart: sends per month from the rollup.
it('builds the 12-month historical send series', function (): void {
    $list = EmailList::factory()->create(['customer_id' => $this->customer->id]);
    $campaign = Campaign::factory()->sent()->create(['customer_id' => $this->customer->id, 'email_list_id' => $list->id]);

    CampaignStatsDaily::query()->create([
        'campaign_id' => $campaign->id, 'day' => now()->startOfMonth()->addDay(), 'sent' => 40,
    ]);
    CampaignStatsDaily::query()->create([
        'campaign_id' => $campaign->id, 'day' => now()->startOfMonth()->addDays(2), 'sent' => 2,
    ]);
    CampaignStatsDaily::query()->create([
        'campaign_id' => $campaign->id, 'day' => now()->subMonthsNoOverflow(2)->startOfMonth(), 'sent' => 7,
    ]);

    // Foreign tenant noise.
    $foreign = Campaign::factory()->sent()->create();
    CampaignStatsDaily::query()->create([
        'campaign_id' => $foreign->id, 'day' => now()->startOfMonth()->addDay(), 'sent' => 500,
    ]);

    $series = app(QuotaUsageService::class)->monthlySendSeries($this->customer);

    expect($series)->toHaveCount(12)
        ->and($series[now()->format('Y-m')])->toBe(42)
        ->and($series[now()->subMonthsNoOverflow(2)->format('Y-m')])->toBe(7)
        ->and($series->sum())->toBe(49);

    // The chart widget feeds straight from the service.
    $widget = new MonthlySendsChart;
    $data = (fn (): array => $this->getData())->call($widget);

    expect(array_sum($data['datasets'][0]['data']))->toBe(49)
        ->and($data['labels'])->toContain(now()->format('Y-m'));
});

// F-2.15.5-007 — upgrade CTA appears near a limit; billing route absent →
// plain contact text (Route::has guard), never a broken link.
it('shows the upgrade CTA only when near a limit', function (): void {
    $this->get(UsageAndQuotas::getUrl(panel: 'customer'))
        ->assertOk()
        ->assertDontSee(__('dashboards.usage.upgrade_heading'));

    // 4 lists against an effective limit of 5 = 80% → near-limit.
    CustomerLimit::query()->create(['customer_id' => $this->customer->id, 'key' => 'max_lists', 'value' => 5]);
    EmailList::factory()->count(4)->create(['customer_id' => $this->customer->id]);

    expect(Route::has('billing.plans'))->toBeFalse();

    $this->get(UsageAndQuotas::getUrl(panel: 'customer'))
        ->assertOk()
        ->assertSee(__('dashboards.usage.upgrade_heading'))
        ->assertSee(__('dashboards.usage.upgrade_contact'));
});

// Cross-guard: the usage page lives in the customer panel only.
it('denies the usage page to guests', function (): void {
    auth('customer')->logout();
    app(CurrentCustomer::class)->set(null);

    $this->get('/customer/usage')->assertRedirect();
});
