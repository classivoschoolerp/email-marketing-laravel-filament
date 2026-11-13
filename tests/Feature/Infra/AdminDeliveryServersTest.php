<?php

declare(strict_types=1);

use App\Enums\DeliveryServerStatus;
use App\Filament\Admin\Resources\DeliveryServerResource;
use App\Filament\Admin\Resources\DeliveryServerResource\Pages\CreateDeliveryServer;
use App\Filament\Admin\Resources\DeliveryServerResource\Pages\DeliveryServerUsageLogs;
use App\Filament\Admin\Resources\DeliveryServerResource\Pages\EditDeliveryServer;
use App\Filament\Admin\Resources\DeliveryServerResource\Pages\ListDeliveryServers;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use App\Models\SendingDomain;
use App\Models\User;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AdminTestHelpers;

afterEach(function (): void {
    app(CurrentCustomer::class)->set(null);
});

// §3.13.1 — index renders for permitted admins with cross-tenant rows
it('renders the admin delivery servers index across all tenants', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $a = DeliveryServer::factory()->create(['name' => 'Tenant A relay']);
    $b = DeliveryServer::factory()->create(['name' => 'Tenant B relay']);
    $system = DeliveryServer::factory()->system()->create(['name' => 'System relay']);

    $this->get(DeliveryServerResource::getUrl('index', panel: 'admin'))->assertOk();

    Livewire::test(ListDeliveryServers::class)
        ->assertCanSeeTableRecords([$a, $b, $system]);
});

it('denies the module to non-permitted admins and customer-guard users', function (): void {
    $this->actingAs(User::factory()->create(), 'admin');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get(DeliveryServerResource::getUrl('index', panel: 'admin'))->assertForbidden();

    // Customer-guard sessions are not admin-authenticated at all.
    auth('admin')->logout();
    $this->actingAs(Customer::factory()->create(), 'customer');
    $response = $this->get(DeliveryServerResource::getUrl('index', panel: 'admin'));
    expect($response->status())->not->toBe(200);
});

// F-3.13.1-001..004 — full config + admin-only fields
it('creates a system server with group assignment, lock, weight and priority', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $group = CustomerGroup::factory()->create();

    Livewire::test(CreateDeliveryServer::class)
        ->fillForm([
            'name' => 'Global SES',
            'type' => 'smtp',
            'hostname' => 'email-smtp.eu-west-1.amazonaws.com',
            'port' => 587,
            'protocol' => 'tls',
            'username' => 'AKIA-user',
            'password' => 'ses-secret',
            'from_email' => 'noreply@platform.test',
            'customer_id' => null,
            'customer_groups' => [$group->id],
            'locked' => true,
            'probability' => 70,
            'priority' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = DeliveryServer::withoutTenancy()->where('name', 'Global SES')->firstOrFail();

    expect($server->customer_id)->toBeNull()
        ->and($server->customer_groups)->toBe([$group->id])
        ->and($server->locked)->toBeTrue()
        ->and($server->probability)->toBe(70)
        ->and((int) $server->priority)->toBe(2)
        ->and($server->status)->toBe(DeliveryServerStatus::Inactive);

    // Secrets encrypted at rest through the admin path too.
    $raw = DB::table('delivery_servers')->where('id', $server->id)->value('password');
    expect($raw)->not->toContain('ses-secret')
        ->and(Crypt::decryptString($raw))->toBe('ses-secret');
});

// F-3.13.1-002 + D-53 — group-assigned system rows are visible to the group's customers
it('shows admin-assigned system servers to customers of the assigned group only', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $group = CustomerGroup::factory()->create();
    $otherGroup = CustomerGroup::factory()->create();

    $assigned = DeliveryServer::factory()->system()->create(['customer_groups' => [$group->id]]);
    $everyone = DeliveryServer::factory()->system()->create(['customer_groups' => null]);
    $foreign = DeliveryServer::factory()->system()->create(['customer_groups' => [$otherGroup->id]]);

    $inGroup = Customer::factory()->create(['customer_group_id' => $group->id]);

    app(CurrentCustomer::class)->set($inGroup);
    $visible = DeliveryServer::query()->browsable()->pluck('id')->all();

    expect($visible)->toContain($assigned->id)
        ->toContain($everyone->id)
        ->not->toContain($foreign->id);

    // Read-only for the customer (D-53): system rows are never editable.
    expect($assigned->isEditableByCustomer($inGroup))->toBeFalse();
});

// F-3.13.1-003 — locked flag: customer loses edit, admin keeps it
it('enforces the locked flag against the owning customer but not admins', function (): void {
    $admin = AdminTestHelpers::actingAdmin(['servers.manage']);

    $customer = Customer::factory()->create();
    $locked = DeliveryServer::factory()->locked()->create(['customer_id' => $customer->id]);

    expect($locked->isEditableByCustomer($customer))->toBeFalse()
        ->and(Gate::forUser($customer)->allows('update', $locked))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('update', $locked))->toBeTrue();

    // Admin edit page renders for the locked row.
    Livewire::test(EditDeliveryServer::class, ['record' => $locked->id])
        ->assertOk();
});

// F-3.13.1-005 — enable/disable, copy, bulk actions
it('toggles, copies and bulk-disables servers from the admin table', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $active = DeliveryServer::factory()->system()->create();
    $inactive = DeliveryServer::factory()->system()->inactive()->create();

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('toggle', $active)
        ->callTableAction('toggle', $inactive)
        ->callTableAction('copy', $active);

    expect($active->refresh()->status)->toBe(DeliveryServerStatus::Disabled)
        ->and($inactive->refresh()->status)->toBe(DeliveryServerStatus::Active);

    $copy = DeliveryServer::withoutTenancy()->where('name', 'like', $active->name.'%')->where('id', '!=', $active->id)->first();
    expect($copy)->not->toBeNull()
        ->and($copy->status)->toBe(DeliveryServerStatus::Inactive)
        ->and($copy->uid)->not->toBe($active->uid);

    // Bulk disable both originals.
    Livewire::test(ListDeliveryServers::class)
        ->callTableBulkAction('disable', [$active->id, $inactive->id]);

    expect($inactive->refresh()->status)->toBe(DeliveryServerStatus::Disabled);
});

// F-3.13.1-006 + F-2.9.1-003 — usage logs page with quota bars + countdown
it('renders the per-server usage logs page with rollups and reset countdown', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $server = DeliveryServer::factory()->system()->create(['hourly_quota' => 100, 'daily_quota' => 1000]);

    DeliveryServerUsage::query()->create([
        'delivery_server_id' => $server->id,
        'date' => today(),
        'sent' => 42,
        'failed' => 3,
    ]);

    $this->get(DeliveryServerResource::getUrl('usage', ['record' => $server], panel: 'admin'))
        ->assertOk()
        ->assertSee('42')
        ->assertSee('3');

    $component = Livewire::test(DeliveryServerUsageLogs::class, ['record' => $server->id]);
    $subheading = $component->instance()->getSubheading();

    expect((string) $subheading)
        ->toContain('resets in')          // hourly reset countdown
        ->toContain('/ 100')              // hourly quota bar
        ->toContain('/ 1000');            // daily quota bar
});

// F-2.9.7-007 — linking a sending domain hands the DKIM key to the server
it('copies dkim material onto the server when linked to a sending domain', function (): void {
    AdminTestHelpers::actingAdmin(['servers.manage']);

    $domain = SendingDomain::factory()->system()->verified()->create([
        'dkim_selector' => 'mailer',
        'dkim_private_key' => "-----BEGIN PRIVATE KEY-----\nlinktest\n-----END PRIVATE KEY-----",
    ]);
    $server = DeliveryServer::factory()->system()->create(['dkim_signing_enabled' => false]);

    Livewire::test(EditDeliveryServer::class, ['record' => $server->id])
        ->fillForm(['sending_domain_id' => $domain->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $server->refresh();
    expect($server->sending_domain_id)->toBe($domain->id)
        ->and($server->dkim_signing_enabled)->toBeTrue()
        ->and($server->dkim_selector)->toBe('mailer')
        ->and($server->dkim_private_key)->toContain('linktest');
});
