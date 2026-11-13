<?php

declare(strict_types=1);

use App\Enums\DeliveryServerStatus;
use App\Filament\Customer\Resources\DeliveryServerResource;
use App\Filament\Customer\Resources\DeliveryServerResource\Pages\CreateDeliveryServer;
use App\Filament\Customer\Resources\DeliveryServerResource\Pages\EditDeliveryServer;
use App\Filament\Customer\Resources\DeliveryServerResource\Pages\ListDeliveryServers;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DeliveryServer;
use App\Sending\DriverRegistry;
use App\Settings\CustomerSettings;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\RecordingSendingDriver;

beforeEach(function (): void {
    $this->group = CustomerGroup::factory()->create();
    $this->customer = Customer::factory()->create(['customer_group_id' => $this->group->id]);
    $this->actingAs($this->customer, 'customer');
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    app(CurrentCustomer::class)->set($this->customer);
});

afterEach(function (): void {
    app(CurrentCustomer::class)->set(null);
});

// §2.9.1 basics — index renders with the core columns
it('renders the delivery servers index', function (): void {
    DeliveryServer::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Main relay',
        'hostname' => 'smtp.acme.example',
        'from_email' => 'news@acme.example',
    ]);

    $this->get(DeliveryServerResource::getUrl('index', panel: 'customer'))
        ->assertOk()
        ->assertSee('Main relay')
        ->assertSee('smtp.acme.example')
        ->assertSee('news@acme.example');
});

// Tenancy + F-2.9.2-037 — own rows + group-assigned system rows only
it('lists own servers plus group-assigned system servers only', function (): void {
    $own = DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);

    // System rows must be created OUTSIDE the tenant context, or the
    // BelongsToCustomer creating-hook would claim them for the tenant.
    [$assigned, $everyone, $otherGroup, $foreign] = app(CurrentCustomer::class)->runAs(null, fn (): array => [
        DeliveryServer::factory()->system()->create(['customer_groups' => [$this->group->id]]),
        DeliveryServer::factory()->system()->create(['customer_groups' => null]),
        DeliveryServer::factory()->system()->create(['customer_groups' => [$this->group->id + 99]]),
        DeliveryServer::factory()->create(),
    ]);

    Livewire::test(ListDeliveryServers::class)
        ->assertCanSeeTableRecords([$own, $assigned, $everyone])
        ->assertCanNotSeeTableRecords([$otherGroup, $foreign]);
});

// F-2.9.2-001/025 — create with the smtp per-type config form
it('creates an smtp server through the dynamic form', function (): void {
    Livewire::test(CreateDeliveryServer::class)
        ->fillForm([
            'name' => 'New relay',
            'type' => 'smtp',
            'hostname' => 'smtp.example.io',
            'port' => 587,
            'protocol' => 'tls',
            'username' => 'mailer',
            'password' => 'topsecret',
            'from_email' => 'from@example.io',
            'from_name' => 'Acme',
            'probability' => 80,
            'hourly_quota' => 500,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = DeliveryServer::query()->where('name', 'New relay')->first();

    expect($server)->not->toBeNull()
        ->and($server->customer_id)->toBe($this->customer->id)
        ->and($server->type)->toBe('smtp')
        ->and($server->status)->toBe(DeliveryServerStatus::Inactive) // must be validated/enabled
        ->and($server->uid)->not->toBeEmpty()
        ->and($server->password)->toBe('topsecret');

    // Encrypted at rest through the UI path too.
    $raw = DB::table('delivery_servers')->where('id', $server->id)->value('password');
    expect($raw)->not->toContain('topsecret')
        ->and(Crypt::decryptString($raw))->toBe('topsecret');
});

// Registry-driven config fields persist into the json config column
it('creates a pickup-directory server with its driver-specific config', function (): void {
    Livewire::test(CreateDeliveryServer::class)
        ->fillForm([
            'name' => 'Spooler',
            'type' => 'pickup-directory',
            'config.pickup_path' => 'C:\\spool\\pickup',
            'from_email' => 'spool@example.io',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $server = DeliveryServer::query()->where('name', 'Spooler')->first();

    expect($server->config)->toBe(['pickup_path' => 'C:\\spool\\pickup']);
});

// The type picker offers only whitelist-allowed drivers (D-21 chain)
it('offers only allowed types and rejects disallowed ones', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->allowed_sending_server_types = ['smtp', 'sendgrid-api'];
    $settings->save();

    expect(array_keys(DeliveryServerResource::allowedTypeOptions()))->toBe(['smtp', 'sendgrid-api']);

    Livewire::test(CreateDeliveryServer::class)
        ->fillForm([
            'name' => 'Nope',
            'type' => 'mailgun-api',
            'from_email' => 'x@y.io',
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(DeliveryServer::query()->where('name', 'Nope')->exists())->toBeFalse();
});

// Permission denial: servers.delivery.add=false hides create entirely
it('denies the create page when the group forbids adding servers', function (): void {
    $this->group->featurePermissions()->create(['key' => 'servers.delivery.add', 'allowed' => false]);

    $this->get(DeliveryServerResource::getUrl('create', panel: 'customer'))->assertForbidden();
});

// Quota denial: max_delivery_servers reached
it('denies the create page at the max_delivery_servers limit', function (): void {
    $this->group->limits()->create(['key' => 'max_delivery_servers', 'value' => 1]);
    DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);

    $this->get(DeliveryServerResource::getUrl('create', panel: 'customer'))->assertForbidden();
});

// Global kill switch: force system servers only
it('denies the create page when the platform forces system servers', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->force_system_servers_only = true;
    $settings->save();

    $this->get(DeliveryServerResource::getUrl('create', panel: 'customer'))->assertForbidden();
});

it('edits an own unlocked server', function (): void {
    $server = DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(EditDeliveryServer::class, ['record' => $server->getRouteKey()])
        ->fillForm(['name' => 'Renamed relay'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($server->refresh()->name)->toBe('Renamed relay');
});

// F-2.9.2-040 — locked rows are read-only for customers
it('denies editing a locked server', function (): void {
    $server = DeliveryServer::factory()->locked()->create(['customer_id' => $this->customer->id]);

    $this->get(DeliveryServerResource::getUrl('edit', ['record' => $server], panel: 'customer'))
        ->assertForbidden();
});

// F-2.9.2-037 — system rows are read-only for customers
it('denies editing a group-assigned system server', function (): void {
    $server = app(CurrentCustomer::class)->runAs(
        null,
        fn () => DeliveryServer::factory()->system()->create(['customer_groups' => [$this->group->id]]),
    );

    $this->get(DeliveryServerResource::getUrl('edit', ['record' => $server], panel: 'customer'))
        ->assertForbidden();
});

it('denies editing another tenant server', function (): void {
    $foreign = app(CurrentCustomer::class)->runAs(null, fn () => DeliveryServer::factory()->create());

    $this->get(DeliveryServerResource::getUrl('edit', ['record' => $foreign], panel: 'customer'))
        ->assertNotFound(); // invisible cross-tenant (browsable query scoping)
});

// F-2.9.2-041 — validate action goes through the actual driver
it('sends the validation email from the table action', function (): void {
    $driver = new RecordingSendingDriver('smtp');
    app(DriverRegistry::class)->register('smtp', $driver);

    $server = DeliveryServer::factory()->create([
        'customer_id' => $this->customer->id,
        'type' => 'smtp',
        'status' => DeliveryServerStatus::Inactive,
    ]);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('validate', $server)
        ->assertNotified(__('sending.notifications.validation_sent'));

    expect($driver->sent)->toHaveCount(1)
        ->and($server->refresh()->status)->toBe(DeliveryServerStatus::InProgress);
});

// Enable requires validation while servers.must_validate is on (default)
it('refuses to enable an unvalidated server while must-validate is on', function (): void {
    $server = DeliveryServer::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => DeliveryServerStatus::Inactive,
        'validated_at' => null,
    ]);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('toggle', $server)
        ->assertNotified(__('sending.notifications.must_validate'));

    expect($server->refresh()->status)->toBe(DeliveryServerStatus::Inactive);
});

it('enables an unvalidated server when the group waives validation', function (): void {
    $this->group->featurePermissions()->create(['key' => 'servers.must_validate', 'allowed' => false]);

    $server = DeliveryServer::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => DeliveryServerStatus::Inactive,
        'validated_at' => null,
    ]);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('toggle', $server);

    expect($server->refresh()->status)->toBe(DeliveryServerStatus::Active);
});

it('disables an active server', function (): void {
    $server = DeliveryServer::factory()->create([
        'customer_id' => $this->customer->id,
        'status' => DeliveryServerStatus::Active,
    ]);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('toggle', $server);

    expect($server->refresh()->status)->toBe(DeliveryServerStatus::Disabled);
});

// §2.9.1 copy action — a copy is a new, unvalidated server
it('copies an own server as a fresh inactive row', function (): void {
    $server = DeliveryServer::factory()->create([
        'customer_id' => $this->customer->id,
        'name' => 'Origin',
        'status' => DeliveryServerStatus::Active,
        'validated_at' => now(),
        'password' => 'copy-me',
    ]);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('copy', $server);

    $copy = DeliveryServer::query()->where('name', 'like', 'Origin %')->first();

    expect($copy)->not->toBeNull()
        ->and($copy->id)->not->toBe($server->id)
        ->and($copy->uid)->not->toBe($server->uid)
        ->and($copy->status)->toBe(DeliveryServerStatus::Inactive)
        ->and($copy->validated_at)->toBeNull()
        ->and($copy->password)->toBe('copy-me');
});

it('refuses to copy past the server quota', function (): void {
    $this->group->limits()->create(['key' => 'max_delivery_servers', 'value' => 1]);

    $server = DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);

    // Livewire converts the gate's ValidationException into form errors —
    // the observable contract is that NO copy row appears.
    Livewire::test(ListDeliveryServers::class)->callTableAction('copy', $server);

    expect(DeliveryServer::query()->count())->toBe(1)
        ->and(DeliveryServer::query()->where('name', 'like', '% '.__('sending.labels.copy_suffix'))->exists())->toBeFalse();
});

it('deletes an own server', function (): void {
    $server = DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);

    Livewire::test(ListDeliveryServers::class)
        ->callTableAction('delete', $server);

    expect(DeliveryServer::query()->find($server->id))->toBeNull();
});

// §2.9.1 — quota usage bars (text form; full UI is WP-15)
it('formats quota usage bars', function (): void {
    expect(DeliveryServerResource::usageBar(5, 10))->toContain('5 / 10')->toContain('50%')
        ->and(DeliveryServerResource::usageBar(0, 0))->toContain(__('sending.labels.unlimited'))
        ->and(DeliveryServerResource::usageBar(20, 10))->toContain('100%');
});
