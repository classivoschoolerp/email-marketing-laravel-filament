<?php

declare(strict_types=1);

use App\Enums\DomainStatus;
use App\Filament\Admin\Resources\TrackingDomainResource as AdminTrackingDomainResource;
use App\Filament\Admin\Resources\TrackingDomainResource\Pages\ListTrackingDomains as AdminListTrackingDomains;
use App\Filament\Customer\Resources\CampaignResource\Pages\CreateCampaign;
use App\Filament\Customer\Resources\TrackingDomainResource;
use App\Filament\Customer\Resources\TrackingDomainResource\Pages\CreateTrackingDomain;
use App\Filament\Customer\Resources\TrackingDomainResource\Pages\ListTrackingDomains;
use App\Jobs\Infra\VerifyDomainsJob;
use App\Models\Campaign;
use App\Models\CronRun;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupPermission;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Models\TrackingDomain;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Domains\TrackingDomainVerifier;
use App\Services\Tracking\TrackingUrlBuilder;
use App\Settings\CronSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;
use Tests\Support\AdminTestHelpers;

function tracking_customer(): Customer
{
    $group = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create(['customer_group_id' => $group->id]);

    test()->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($customer);

    return $customer;
}

afterEach(function (): void {
    app(CurrentCustomer::class)->set(null);
});

// F-2.9.8-001/002 — add domain + CNAME record display
it('creates a customer tracking domain and shows the cname target', function (): void {
    $customer = tracking_customer();

    Livewire::test(CreateTrackingDomain::class)
        ->fillForm(['name' => 'Track.Acme.example'])
        ->call('create')
        ->assertHasNoFormErrors();

    $domain = TrackingDomain::withoutTenancy()->where('customer_id', $customer->id)->firstOrFail();

    expect($domain->name)->toBe('track.acme.example')
        ->and($domain->verified)->toBeFalse()
        ->and($domain->ssl_status)->toBe('none')
        ->and($domain->status)->toBe(DomainStatus::Pending);

    // CNAME target derives from app.url unless configured (F-2.9.8-002).
    expect(TrackingDomain::cnameTarget())->toBe((string) parse_url((string) config('app.url'), PHP_URL_HOST));

    config()->set('emp-infra.tracking.cname_target', 'edge.platform.example');
    expect(TrackingDomain::cnameTarget())->toBe('edge.platform.example');
});

// F-2.9.8-003 — DNS verify via CNAME check
it('verifies a tracking domain whose cname points at the platform', function (): void {
    $domain = TrackingDomain::factory()->create(['name' => 'track.good.example']);

    app()->instance(DnsResolverInterface::class, new FakeDnsResolver(cname: [
        'track.good.example' => TrackingDomain::cnameTarget(),
    ]));

    app(TrackingDomainVerifier::class)->verify($domain);

    $domain->refresh();
    expect($domain->verified)->toBeTrue()
        ->and($domain->status)->toBe(DomainStatus::Verified)
        ->and($domain->last_checked_at)->not->toBeNull();
});

it('fails verification when the cname is missing or wrong', function (): void {
    $missing = TrackingDomain::factory()->create(['name' => 'track.none.example']);
    $wrong = TrackingDomain::factory()->create(['name' => 'track.wrong.example']);

    app()->instance(DnsResolverInterface::class, new FakeDnsResolver(cname: [
        'track.wrong.example' => 'unrelated.example.net',
    ]));

    app(TrackingDomainVerifier::class)->verify($missing);
    app(TrackingDomainVerifier::class)->verify($wrong);

    expect($missing->refresh()->status)->toBe(DomainStatus::Failed)
        ->and($wrong->refresh()->status)->toBe(DomainStatus::Failed)
        ->and($wrong->verified)->toBeFalse();
});

// tracking-domains:verify command (D-40 wrapper + toggle)
it('re-verifies all tracking domains via the command', function (): void {
    $good = TrackingDomain::factory()->create(['name' => 'track.pass.example']);
    $bad = TrackingDomain::factory()->system()->create(['name' => 'track.fail.example']);

    app()->instance(DnsResolverInterface::class, new FakeDnsResolver(cname: [
        'track.pass.example' => TrackingDomain::cnameTarget(),
    ]));

    $this->artisan('tracking-domains:verify')->assertSuccessful();

    expect($good->refresh()->status)->toBe(DomainStatus::Verified)
        ->and($bad->refresh()->status)->toBe(DomainStatus::Failed);

    $run = CronRun::query()->where('job', 'tracking-domains:verify')->firstOrFail();
    expect($run->processed)->toBe(2);
});

it('skips tracking-domains:verify when disabled', function (): void {
    $settings = app(CronSettings::class);
    $settings->enabled_jobs = array_values(array_diff($settings->enabled_jobs, ['tracking-domains:verify']));
    $settings->save();

    TrackingDomain::factory()->create();

    $this->artisan('tracking-domains:verify')->assertSuccessful();

    expect(CronRun::query()->where('job', 'tracking-domains:verify')->exists())->toBeFalse();
});

// TrackingUrlBuilder — the WP-13 seam for host-aware link generation
it('builds tracking base urls from verified domains only', function (): void {
    $builder = app(TrackingUrlBuilder::class);
    $appBase = rtrim((string) config('app.url'), '/');

    $unverified = TrackingDomain::factory()->create(['name' => 'track.unverified.example']);
    $verified = TrackingDomain::factory()->verified()->create(['name' => 'track.verified.example']);
    $ssl = TrackingDomain::factory()->verified()->create(['name' => 'track.ssl.example', 'ssl_status' => 'active']);

    expect($builder->baseUrl(null))->toBe($appBase)
        ->and($builder->baseUrl($unverified))->toBe($appBase)
        ->and($builder->baseUrl($verified))->toBe('http://track.verified.example')
        ->and($builder->baseUrl($ssl))->toBe('https://track.ssl.example')
        ->and($builder->forTrackingDomainId($verified->id))->toBe('http://track.verified.example')
        ->and($builder->forTrackingDomainId(null))->toBe($appBase)
        ->and($builder->forTrackingDomainId(999999))->toBe($appBase);

    // Campaign-shaped consumption (campaigns.tracking_domain_id, WP-11 column).
    $campaign = Campaign::factory()->create(['tracking_domain_id' => $verified->id]);
    expect($builder->forCampaign($campaign))->toBe('http://track.verified.example')
        ->and($builder->forCampaign(null))->toBe($appBase);

    // Rebase rewrites app-url links onto the tracking host.
    expect($builder->rebase($appBase.'/campaigns/x/track-opening/y', $verified))
        ->toBe('http://track.verified.example/campaigns/x/track-opening/y')
        ->and($builder->rebase('https://elsewhere.example/x', $verified))
        ->toBe('https://elsewhere.example/x');
});

// F-2.9.8-004 — tracking routes answer on a custom Host (serving side)
it('serves the open pixel and click redirect on a custom tracking host', function (): void {
    $customer = Customer::factory()->create();
    $list = EmailList::factory()->for($customer)->create();
    $subscriber = Subscriber::factory()->for($customer)->for($list, 'list')->create();

    $response = $this->withHeaders(['Host' => 'track.custom.example'])->get(
        '/campaigns/camp-host-1/track-opening/'.$subscriber->uid,
    );

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');

    expect(DB::table('campaign_track_opens')->where('campaign_uid', 'camp-host-1')->exists())->toBeTrue();
});

// F-2.9.8-004 — assignment select on the campaign form is live now
it('offers the customer tracking domains on the campaign form', function (): void {
    $customer = tracking_customer();

    $own = TrackingDomain::factory()->create(['customer_id' => $customer->id, 'name' => 'track.mine.example']);
    app(CurrentCustomer::class)->runAs(null, fn () => TrackingDomain::factory()->create(['name' => 'track.other.example']));

    Livewire::test(CreateCampaign::class)
        ->assertFormFieldExists('tracking_domain_id');

    // The WP-11 Schema-guarded select plucks name by customer.
    $this->get(\App\Filament\Customer\Resources\CampaignResource::getUrl('create', panel: 'customer'))
        ->assertOk()
        ->assertSee('track.mine.example')
        ->assertDontSee('track.other.example');
});

// Tenancy + admin
it('scopes tracking domains per tenant', function (): void {
    $customer = tracking_customer();
    $own = TrackingDomain::factory()->create(['customer_id' => $customer->id]);

    $foreign = app(CurrentCustomer::class)->runAs(null, fn () => TrackingDomain::factory()->create());

    Livewire::test(ListTrackingDomains::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign]);

    expect(Gate::forUser($customer)->allows('update', $foreign))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('delete', $foreign))->toBeFalse();
});

it('gates customer creation by domains.tracking.add and quota', function (): void {
    $customer = tracking_customer();

    CustomerGroupPermission::query()->create([
        'customer_group_id' => $customer->customer_group_id,
        'key' => 'domains.tracking.add',
        'allowed' => false,
    ]);

    expect(Gate::forUser($customer)->allows('create', TrackingDomain::class))->toBeFalse();
    $this->get(TrackingDomainResource::getUrl('create', panel: 'customer'))->assertForbidden();

    CustomerGroupPermission::query()->update(['allowed' => true]);
    $customer->group->limits()->create(['key' => 'max_tracking_domains', 'value' => 1]);
    TrackingDomain::factory()->create(['customer_id' => $customer->id]);

    expect(Gate::forUser($customer->fresh())->allows('create', TrackingDomain::class))->toBeFalse();
});

it('lists all tracking domains in the admin panel with queued verify-all', function (): void {
    Bus::fake();
    AdminTestHelpers::actingAdmin(['domains.manage']);

    $a = TrackingDomain::factory()->create();
    $b = TrackingDomain::factory()->system()->create();

    $this->get(AdminTrackingDomainResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(AdminListTrackingDomains::class)
        ->assertCanSeeTableRecords([$a, $b])
        ->callAction('verify_all');

    Bus::assertDispatched(
        VerifyDomainsJob::class,
        fn (VerifyDomainsJob $job): bool => $job->type === VerifyDomainsJob::TYPE_TRACKING,
    );

    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');
    expect($this->get(AdminTrackingDomainResource::getUrl('index', panel: 'admin'))->status())->not->toBe(200);
});
