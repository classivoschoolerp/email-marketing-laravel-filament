<?php

declare(strict_types=1);

use App\Enums\DomainStatus;
use App\Filament\Admin\Resources\SendingDomainResource as AdminSendingDomainResource;
use App\Filament\Admin\Resources\SendingDomainResource\Pages\ListSendingDomains as AdminListSendingDomains;
use App\Filament\Customer\Resources\SendingDomainResource;
use App\Filament\Customer\Resources\SendingDomainResource\Pages\CreateSendingDomain;
use App\Filament\Customer\Resources\SendingDomainResource\Pages\ListSendingDomains;
use App\Filament\Customer\Resources\SendingDomainResource\Pages\ViewSendingDomain;
use App\Jobs\Infra\VerifyDomainsJob;
use App\Models\CronRun;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupPermission;
use App\Models\DeliveryServer;
use App\Models\SendingDomain;
use App\Services\Dkim\GenerateDkimKeypair;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Domains\SendingDomainRecords;
use App\Services\Domains\SendingDomainVerifier;
use App\Services\Domains\SyncDkimToServers;
use App\Settings\CronSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeDnsResolver;
use Tests\Support\AdminTestHelpers;

function acting_customer(): Customer
{
    $group = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create(['customer_group_id' => $group->id]);

    test()->actingAs($customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($customer);

    return $customer;
}

function dns_for(SendingDomain $domain, bool $dkim = true, bool $spf = true, bool $dmarc = true, bool $mx = true): FakeDnsResolver
{
    $records = app(SendingDomainRecords::class)->for($domain);

    $resolver = new FakeDnsResolver(
        txt: array_filter([
            strtolower($records['dkim']['name']) => $dkim ? [$records['dkim']['value']] : null,
            strtolower($domain->name) => $spf ? [$records['spf']['value']] : null,
            strtolower('_dmarc.'.$domain->name) => $dmarc ? [$records['dmarc']['value']] : null,
        ]),
        mx: $mx ? [strtolower($domain->name) => ['mx1.'.$domain->name]] : [],
    );

    app()->instance(DnsResolverInterface::class, $resolver);

    return $resolver;
}

afterEach(function (): void {
    app(CurrentCustomer::class)->set(null);
});

// F-2.9.7-001 — add domain, auto-generated DKIM RSA-2048 keypair
it('creates a customer sending domain with a generated dkim keypair encrypted at rest', function (): void {
    $customer = acting_customer();

    Livewire::test(CreateSendingDomain::class)
        ->fillForm([
            'name' => 'Mail.Acme-Corp.example',
            'dkim_selector' => 'default',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $domain = SendingDomain::withoutTenancy()->where('customer_id', $customer->id)->firstOrFail();

    expect($domain->name)->toBe('mail.acme-corp.example') // normalised lowercase
        ->and($domain->status)->toBe(DomainStatus::Pending)
        ->and($domain->dkim_private_key)->toContain('PRIVATE KEY')
        ->and($domain->dkim_public_key)->toContain('PUBLIC KEY')
        ->and($domain->dkimPublicKeyBase64())->not->toBe('');

    // RSA-2048 sanity: the public key parses and reports 2048 bits.
    $details = openssl_pkey_get_details(openssl_pkey_get_public($domain->dkim_public_key));
    expect($details['bits'])->toBe(2048);

    // Private key encrypted at rest — raw DB assertion.
    $raw = DB::table('sending_domains')->where('id', $domain->id)->value('dkim_private_key');
    expect($raw)->not->toContain('PRIVATE KEY')
        ->and(Crypt::decryptString($raw))->toContain('PRIVATE KEY');
});

// F-2.9.7-002/005 — required DNS records incl. names and copyable values
it('builds the four dns records for a domain', function (): void {
    $domain = SendingDomain::factory()->create([
        'name' => 'mail.acme.example',
        'dkim_selector' => 'sel1',
    ]);

    $records = app(SendingDomainRecords::class)->for($domain);

    expect($records['dkim']['name'])->toBe('sel1._domainkey.mail.acme.example')
        ->and($records['dkim']['value'])->toStartWith('v=DKIM1; k=rsa; p=')
        ->and($records['spf']['name'])->toBe('mail.acme.example')
        ->and($records['spf']['value'])->toContain('v=spf1 include:')
        ->and($records['dmarc']['name'])->toBe('_dmarc.mail.acme.example')
        ->and($records['dmarc']['value'])->toStartWith('v=DMARC1')
        ->and($records['mx']['required'])->toBeFalse();
});

it('renders the view page with copyable records', function (): void {
    $customer = acting_customer();
    $domain = SendingDomain::factory()->create(['customer_id' => $customer->id, 'dkim_selector' => 'sel2']);

    $this->get(SendingDomainResource::getUrl('view', ['record' => $domain], panel: 'customer'))
        ->assertOk()
        ->assertSee('sel2._domainkey.'.$domain->name)
        ->assertSee('_dmarc.'.$domain->name);
});

// F-2.9.7-003/006 — per-record pass/fail matrix + badge
it('verifies a domain when all required records are published', function (): void {
    $domain = SendingDomain::factory()->create();
    dns_for($domain);

    app(SendingDomainVerifier::class)->verify($domain);

    $domain->refresh();
    expect($domain->dkim_verified)->toBeTrue()
        ->and($domain->spf_verified)->toBeTrue()
        ->and($domain->dmarc_verified)->toBeTrue()
        ->and($domain->mx_verified)->toBeTrue()
        ->and($domain->status)->toBe(DomainStatus::Verified)
        ->and($domain->last_checked_at)->not->toBeNull();
});

it('fails verification per missing record', function (bool $dkim, bool $spf, bool $dmarc, array $expectedFlags): void {
    $domain = SendingDomain::factory()->create();
    dns_for($domain, dkim: $dkim, spf: $spf, dmarc: $dmarc, mx: false);

    app(SendingDomainVerifier::class)->verify($domain);

    $domain->refresh();
    expect($domain->status)->toBe(DomainStatus::Failed)
        ->and($domain->dkim_verified)->toBe($expectedFlags[0])
        ->and($domain->spf_verified)->toBe($expectedFlags[1])
        ->and($domain->dmarc_verified)->toBe($expectedFlags[2])
        ->and($domain->mx_verified)->toBeFalse();
})->with([
    'spf missing' => [true, false, true, [true, false, true]],
    'dkim missing' => [false, true, true, [false, true, true]],
    'dmarc missing' => [true, true, false, [true, true, false]],
    'all missing' => [false, false, false, [false, false, false]],
]);

it('rejects a dkim record whose published key differs from the stored one', function (): void {
    $domain = SendingDomain::factory()->create();

    app()->instance(DnsResolverInterface::class, new FakeDnsResolver(txt: [
        strtolower($domain->dkimRecordName()) => ['v=DKIM1; k=rsa; p=SOMEOTHERKEY'],
    ]));

    app(SendingDomainVerifier::class)->verify($domain);

    expect($domain->refresh()->dkim_verified)->toBeFalse();
});

// One-click verify from the UI (single domain — inline; bulk is queued)
it('verifies from the view page header action', function (): void {
    $customer = acting_customer();
    $domain = SendingDomain::factory()->create(['customer_id' => $customer->id]);
    dns_for($domain);

    Livewire::test(ViewSendingDomain::class, ['record' => $domain->id])
        ->callAction('verify')
        ->assertNotified(__('infra.notifications.domain_verified'));

    expect($domain->refresh()->status)->toBe(DomainStatus::Verified);
});

// F-2.9.7-004 — daily re-verification command (D-40 wrapper + toggle)
it('re-verifies every domain across tenants via sending-domains:verify', function (): void {
    $verified = SendingDomain::factory()->create();
    $failing = SendingDomain::factory()->system()->create();

    dns_for($verified); // resolver only knows the first domain's records

    $this->artisan('sending-domains:verify')->assertSuccessful();

    expect($verified->refresh()->status)->toBe(DomainStatus::Verified)
        ->and($failing->refresh()->status)->toBe(DomainStatus::Failed);

    $run = CronRun::query()->where('job', 'sending-domains:verify')->firstOrFail();
    expect($run->status)->toBe(CronRun::STATUS_SUCCESS)
        ->and($run->processed)->toBe(2);
});

it('skips sending-domains:verify when disabled in cron settings', function (): void {
    $settings = app(CronSettings::class);
    $settings->enabled_jobs = array_values(array_diff($settings->enabled_jobs, ['sending-domains:verify']));
    $settings->save();

    SendingDomain::factory()->create();

    $this->artisan('sending-domains:verify')->assertSuccessful();

    expect(CronRun::query()->where('job', 'sending-domains:verify')->exists())->toBeFalse();
});

// F-2.9.7-007 — link to delivery servers + DKIM handoff
it('syncs dkim material to every linked delivery server', function (): void {
    $domain = SendingDomain::factory()->system()->create(['dkim_selector' => 'push1']);
    $linked = DeliveryServer::factory()->system()->create(['sending_domain_id' => $domain->id, 'dkim_signing_enabled' => false]);
    $unlinked = DeliveryServer::factory()->system()->create(['dkim_signing_enabled' => false]);

    $count = app(SyncDkimToServers::class)->syncLinkedServers($domain);

    expect($count)->toBe(1)
        ->and($linked->refresh()->dkim_signing_enabled)->toBeTrue()
        ->and($linked->dkim_selector)->toBe('push1')
        ->and($linked->dkim_private_key)->toBe($domain->dkim_private_key)
        ->and($unlinked->refresh()->dkim_signing_enabled)->toBeFalse();
});

// Tenancy — customers see and reach only their own domains
it('scopes customer domains by tenant and denies cross-tenant access', function (): void {
    $customer = acting_customer();
    $own = SendingDomain::factory()->create(['customer_id' => $customer->id]);

    [$foreign, $system] = app(CurrentCustomer::class)->runAs(null, fn (): array => [
        SendingDomain::factory()->create(),
        SendingDomain::factory()->system()->create(),
    ]);

    Livewire::test(ListSendingDomains::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$foreign, $system]);

    $this->get(SendingDomainResource::getUrl('view', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound(); // tenant scope hides the row entirely

    expect(Gate::forUser($customer)->allows('view', $foreign))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('update', $system))->toBeFalse();
});

// D-42 matrix — domains.sending.add permission + max_sending_domains quota
it('gates customer creation by group permission and quota', function (): void {
    $customer = acting_customer();

    // Permission off → create denied.
    CustomerGroupPermission::query()->create([
        'customer_group_id' => $customer->customer_group_id,
        'key' => 'domains.sending.add',
        'allowed' => false,
    ]);

    expect(Gate::forUser($customer)->allows('create', SendingDomain::class))->toBeFalse();
    $this->get(SendingDomainResource::getUrl('create', panel: 'customer'))->assertForbidden();

    // Permission on but quota reached → still denied.
    CustomerGroupPermission::query()->update(['allowed' => true]);
    $customer->group->limits()->create(['key' => 'max_sending_domains', 'value' => 1]);
    SendingDomain::factory()->create(['customer_id' => $customer->id]);

    expect(Gate::forUser($customer->fresh())->allows('create', SendingDomain::class))->toBeFalse();
});

// §3.13.5 — admin global list + verify-all (queued)
it('lists all domains in the admin panel and queues verify-all', function (): void {
    Bus::fake();
    AdminTestHelpers::actingAdmin(['domains.manage']);

    $a = SendingDomain::factory()->create();
    $b = SendingDomain::factory()->system()->create();

    $this->get(AdminSendingDomainResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(AdminListSendingDomains::class)
        ->assertCanSeeTableRecords([$a, $b])
        ->callAction('verify_all');

    Bus::assertDispatched(
        VerifyDomainsJob::class,
        fn (VerifyDomainsJob $job): bool => $job->type === VerifyDomainsJob::TYPE_SENDING,
    );
});

it('renders the admin domain view page and syncs dkim from the table action', function (): void {
    AdminTestHelpers::actingAdmin(['domains.manage']);

    $domain = SendingDomain::factory()->system()->create(['dkim_selector' => 'admsel']);
    $linked = DeliveryServer::factory()->system()->create(['sending_domain_id' => $domain->id, 'dkim_signing_enabled' => false]);

    $this->get(AdminSendingDomainResource::getUrl('view', ['record' => $domain], panel: 'admin'))
        ->assertOk()
        ->assertSee('admsel._domainkey.'.$domain->name);

    Livewire::test(AdminListSendingDomains::class)
        ->callTableAction('sync_dkim', $domain);

    expect($linked->refresh()->dkim_signing_enabled)->toBeTrue()
        ->and($linked->dkim_selector)->toBe('admsel');
});

it('generates the keypair on admin create too and denies customer-guard users', function (): void {
    AdminTestHelpers::actingAdmin(['domains.manage']);

    Livewire::test(AdminSendingDomainResource\Pages\CreateSendingDomain::class)
        ->fillForm(['name' => 'bulk.platform.example', 'dkim_selector' => 'default', 'customer_id' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    $domain = SendingDomain::withoutTenancy()->where('name', 'bulk.platform.example')->firstOrFail();
    expect($domain->customer_id)->toBeNull()
        ->and($domain->dkim_private_key)->toContain('PRIVATE KEY');

    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');
    expect($this->get(AdminSendingDomainResource::getUrl('index', panel: 'admin'))->status())->not->toBe(200);
});

// The keypair generator itself (reused WP-05 service, D-37 fallback)
it('generates rsa-2048 keypairs through the shared dkim service', function (): void {
    $pair = app(GenerateDkimKeypair::class)();

    expect($pair['private_key'])->toContain('PRIVATE KEY')
        ->and($pair['public_key'])->toContain('PUBLIC KEY');
});
