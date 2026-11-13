<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Services\Quota\QuotaService;

// F-0.3-005 — quota/limit matrix per customer group
it('reads per-group quota overrides with config defaults', function (): void {
    $group = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create(['customer_group_id' => $group->id]);

    $quota = app(QuotaService::class);

    // No row → config default.
    expect($quota->limitFor($customer, 'max_lists'))
        ->toBe((int) config('emp.quotas.defaults.max_lists'));

    // Per-group override wins.
    $group->limits()->create(['key' => 'max_lists', 'value' => 3]);

    expect($quota->limitFor($customer, 'max_lists'))->toBe(3)
        ->and($quota->withinLimit($customer, 'max_lists', 2))->toBeTrue()
        ->and($quota->withinLimit($customer, 'max_lists', 3))->toBeFalse();

    // null value = unlimited.
    $group->limits()->create(['key' => 'sends_per_hour', 'value' => null]);

    expect($quota->limitFor($customer, 'sends_per_hour'))->toBeNull()
        ->and($quota->withinLimit($customer, 'sends_per_hour', PHP_INT_MAX - 1))->toBeTrue();
});

it('covers every quota key from the spec with a sensible default', function (): void {
    // spec §0.3: lists, subscribers, campaigns, servers, sends/hour, sends/day, sends/month
    foreach ([
        'max_lists', 'max_subscribers', 'max_campaigns', 'max_delivery_servers',
        'sends_per_hour', 'sends_per_day', 'sends_per_month',
    ] as $key) {
        expect(config('emp.quotas.defaults'))->toHaveKey($key);
    }
});

// F-0.3-004 — permission matrix per customer group, every feature toggleable
it('reads per-group feature toggles with defaults', function (): void {
    $group = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create(['customer_group_id' => $group->id]);

    $quota = app(QuotaService::class);

    // Default: allowed.
    expect($quota->allows($customer, 'delivery_servers.manage'))->toBeTrue();

    // Per-group toggle off.
    $group->featurePermissions()->create(['key' => 'delivery_servers.manage', 'allowed' => false]);

    expect($quota->allows($customer, 'delivery_servers.manage'))->toBeFalse()
        ->and($quota->allows($customer, 'campaigns.send'))->toBeTrue();

    // Customers without a group fall back to defaults.
    $ungrouped = Customer::factory()->create(['customer_group_id' => null]);

    expect($quota->allows($ungrouped, 'delivery_servers.manage'))->toBeTrue();
});

it('exposes the matrices as customer group relationships', function (): void {
    $group = CustomerGroup::factory()->create();

    $group->limits()->create(['key' => 'max_lists', 'value' => 5]);
    $group->featurePermissions()->create(['key' => 'surveys.use', 'allowed' => false]);

    expect($group->limits)->toHaveCount(1)
        ->and($group->featurePermissions)->toHaveCount(1)
        ->and($group->limits->first()->group->is($group))->toBeTrue()
        ->and($group->featurePermissions->first()->group->is($group))->toBeTrue();
});
