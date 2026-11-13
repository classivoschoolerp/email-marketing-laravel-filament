<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DeliveryServer;
use App\Services\Sending\DeliveryServerQuota;
use App\Settings\CustomerSettings;
use App\Support\Tenancy\CurrentCustomer;

beforeEach(function (): void {
    $this->group = CustomerGroup::factory()->create();
    $this->customer = Customer::factory()->create(['customer_group_id' => $this->group->id]);
    app(CurrentCustomer::class)->set($this->customer);
});

afterEach(function (): void {
    app(CurrentCustomer::class)->set(null);
});

// ---- create gate: max_delivery_servers chain (F-2.9.2 + §0.3 quota matrix)

it('allows creation within the config-default quota', function (): void {
    expect($this->customer->can('create', DeliveryServer::class))->toBeTrue();
});

it('denies creation at the group max_delivery_servers limit', function (): void {
    $this->group->limits()->create(['key' => 'max_delivery_servers', 'value' => 1]);
    DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);

    expect($this->customer->can('create', DeliveryServer::class))->toBeFalse();
});

it('lets a per-customer limit override re-allow beyond the group limit', function (): void {
    $this->group->limits()->create(['key' => 'max_delivery_servers', 'value' => 1]);
    $this->customer->limitOverrides()->create(['key' => 'max_delivery_servers', 'value' => 5]);
    DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);

    expect($this->customer->can('create', DeliveryServer::class))->toBeTrue();
});

it('counts soft-deleted servers against the quota', function (): void {
    $this->group->limits()->create(['key' => 'max_delivery_servers', 'value' => 1]);
    DeliveryServer::factory()->create(['customer_id' => $this->customer->id])->delete();

    expect($this->customer->can('create', DeliveryServer::class))->toBeFalse();
});

// ---- create gate: servers.delivery.add permission (D-42 matrix)

it('denies creation when the group forbids adding delivery servers', function (): void {
    $this->group->featurePermissions()->create(['key' => 'servers.delivery.add', 'allowed' => false]);

    expect($this->customer->can('create', DeliveryServer::class))->toBeFalse();
});

it('lets a per-customer permission override re-allow adding servers', function (): void {
    $this->group->featurePermissions()->create(['key' => 'servers.delivery.add', 'allowed' => false]);
    $this->customer->permissionOverrides()->create(['key' => 'servers.delivery.add', 'allowed' => true]);

    expect($this->customer->can('create', DeliveryServer::class))->toBeTrue();
});

// ---- create gate: global force-system-servers-only kill switch (§3.25.4)

it('denies creation when the platform forces system servers only', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->force_system_servers_only = true;
    $settings->save();

    expect($this->customer->can('create', DeliveryServer::class))->toBeFalse();
});

// ---- type whitelist chain (D-21): global settings → group matrix → customer override

it('checks the global allowed-types whitelist first', function (): void {
    $quota = app(DeliveryServerQuota::class);

    expect($quota->typeAllowed($this->customer, 'smtp'))->toBeTrue();

    $settings = app(CustomerSettings::class);
    $settings->allowed_sending_server_types = ['sendgrid-api'];
    $settings->save();

    expect($quota->typeAllowed($this->customer, 'smtp'))->toBeFalse()
        ->and($quota->typeAllowed($this->customer, 'sendgrid-api'))->toBeTrue()
        ->and($quota->allowedTypes($this->customer, CustomerSettings::SENDING_SERVER_TYPES))->toBe(['sendgrid-api']);
});

it('lets the group matrix deny an allowed type', function (): void {
    $this->group->featurePermissions()->create(['key' => 'servers.type.smtp', 'allowed' => false]);

    expect(app(DeliveryServerQuota::class)->typeAllowed($this->customer, 'smtp'))->toBeFalse()
        ->and(app(DeliveryServerQuota::class)->typeAllowed($this->customer, 'mailgun-api'))->toBeTrue();
});

it('lets a per-customer override re-allow a group-denied type', function (): void {
    $this->group->featurePermissions()->create(['key' => 'servers.type.smtp', 'allowed' => false]);
    $this->customer->permissionOverrides()->create(['key' => 'servers.type.smtp', 'allowed' => true]);

    expect(app(DeliveryServerQuota::class)->typeAllowed($this->customer, 'smtp'))->toBeTrue();
});

it('never lets a customer override bypass the global whitelist', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->allowed_sending_server_types = ['sendgrid-api'];
    $settings->save();

    $this->customer->permissionOverrides()->create(['key' => 'servers.type.smtp', 'allowed' => true]);

    expect(app(DeliveryServerQuota::class)->typeAllowed($this->customer, 'smtp'))->toBeFalse();
});

it('asserts the full chain on assertCanCreate', function (): void {
    $quota = app(DeliveryServerQuota::class);

    $quota->assertCanCreate($this->customer, 'smtp'); // passes

    $settings = app(CustomerSettings::class);
    $settings->allowed_sending_server_types = ['sendgrid-api'];
    $settings->save();

    expect(fn () => $quota->assertCanCreate($this->customer, 'smtp'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ---- read/write rules on rows (F-2.9.2-037/040)

it('gives customers full control of their own unlocked rows only', function (): void {
    $own = DeliveryServer::factory()->create(['customer_id' => $this->customer->id]);
    $locked = DeliveryServer::factory()->locked()->create(['customer_id' => $this->customer->id]);
    $foreign = app(CurrentCustomer::class)->runAs(null, fn () => DeliveryServer::factory()->create());

    expect($this->customer->can('view', $own))->toBeTrue()
        ->and($this->customer->can('update', $own))->toBeTrue()
        ->and($this->customer->can('delete', $own))->toBeTrue()
        // admin-locked: read-only (F-2.9.2-040)
        ->and($this->customer->can('view', $locked))->toBeTrue()
        ->and($this->customer->can('update', $locked))->toBeFalse()
        ->and($this->customer->can('delete', $locked))->toBeFalse()
        // cross-tenant: invisible and immutable
        ->and($this->customer->can('view', $foreign))->toBeFalse()
        ->and($this->customer->can('update', $foreign))->toBeFalse();
});

it('keeps group-assigned system servers strictly read-only', function (): void {
    $system = app(CurrentCustomer::class)->runAs(
        null,
        fn () => DeliveryServer::factory()->system()->create(['customer_groups' => [$this->group->id]]),
    );

    expect($this->customer->can('view', $system))->toBeTrue()
        ->and($this->customer->can('update', $system))->toBeFalse()
        ->and($this->customer->can('delete', $system))->toBeFalse();
});

it('hides system servers assigned to other groups', function (): void {
    $otherGroup = CustomerGroup::factory()->create();
    $system = app(CurrentCustomer::class)->runAs(
        null,
        fn () => DeliveryServer::factory()->system()->create(['customer_groups' => [$otherGroup->id]]),
    );

    expect($this->customer->can('view', $system))->toBeFalse();
});
