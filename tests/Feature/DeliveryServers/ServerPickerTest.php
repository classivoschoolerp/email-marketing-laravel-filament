<?php

declare(strict_types=1);

use App\Enums\DeliveryServerStatus;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerUsage;
use App\Sending\ServerPicker;
use App\Sending\ServerUsage;
use App\Services\Sending\DeliveryServerQuota;
use App\Settings\CustomerSettings;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * F-2.9.2-022/029/030/042 — multi-server load balancing with quota headroom,
 * pause-vs-next behaviour and automatic hourly-status transitions.
 */

beforeEach(function (): void {
    $this->group = CustomerGroup::factory()->create();
    $this->customer = Customer::factory()->create(['customer_group_id' => $this->group->id]);
    app(CurrentCustomer::class)->set(null); // picker binds the tenant itself
});

function picker(int $seed = 1): ServerPicker
{
    return new ServerPicker(
        app(ServerUsage::class),
        app(DeliveryServerQuota::class),
        new Randomizer(new Mt19937($seed)),
    );
}

function ownServer(Customer $customer, array $attributes = []): DeliveryServer
{
    return DeliveryServer::factory()->create(array_merge([
        'customer_id' => $customer->id,
        'status' => DeliveryServerStatus::Active,
        'probability' => 100,
    ], $attributes));
}

it('picks among the customer servers and group-assigned system servers', function (): void {
    $own = ownServer($this->customer);
    $system = DeliveryServer::factory()->system()->create(['customer_groups' => [$this->group->id]]);
    DeliveryServer::factory()->system()->create(['customer_groups' => [$this->group->id + 999]]); // other group
    DeliveryServer::factory()->create(); // other tenant

    $candidates = collect(picker()->candidatesFor($this->customer))->pluck('id')->all();

    expect($candidates)->toBe([$own->id, $system->id]);
});

it('skips inactive and disabled servers', function (): void {
    ownServer($this->customer, ['status' => DeliveryServerStatus::Inactive]);
    ownServer($this->customer, ['status' => DeliveryServerStatus::Disabled]);
    $active = ownServer($this->customer);

    $result = picker()->pickFor($this->customer);

    expect($result->picked())->toBeTrue()
        ->and($result->server->id)->toBe($active->id);
});

it('skips types the customer may not use', function (): void {
    $sendgrid = ownServer($this->customer, ['type' => 'sendgrid-api', 'api_key' => 'k']);
    ownServer($this->customer, ['type' => 'smtp']);

    $settings = app(CustomerSettings::class);
    $settings->allowed_sending_server_types = ['sendgrid-api'];
    $settings->save();

    $result = picker()->pickFor($this->customer);

    expect($result->server?->id)->toBe($sendgrid->id);
});

// F-2.9.2-030 — move-to-next: exhausted server is skipped
it('moves to the next server when an exhausted server says so', function (): void {
    $exhausted = ownServer($this->customer, ['hourly_quota' => 5, 'quota_exceeded_action' => 'next']);
    $fallback = ownServer($this->customer, ['hourly_quota' => 0]);

    app(ServerUsage::class)->recordSend($exhausted, 5);

    $result = picker()->pickFor($this->customer);

    expect($result->picked())->toBeTrue()
        ->and($result->server->id)->toBe($fallback->id)
        // F-2.9.2-042 — the exhausted server flipped to hourly-quota-reached
        ->and($exhausted->refresh()->status)->toBe(DeliveryServerStatus::HourlyQuotaReached);
});

// F-2.9.2-030 — pause: exhausted server demands waiting for the window
it('pauses when an exhausted server is configured to pause', function (): void {
    $paused = ownServer($this->customer, ['hourly_quota' => 3, 'quota_exceeded_action' => 'pause']);
    ownServer($this->customer, ['hourly_quota' => 0]); // other capacity exists but must NOT absorb

    app(ServerUsage::class)->recordSend($paused, 3);

    $result = picker()->pickFor($this->customer);

    expect($result->picked())->toBeFalse()
        ->and($result->shouldPause)->toBeTrue()
        ->and($result->retryAfterSeconds)->toBeGreaterThan(0)
        ->and($result->retryAfterSeconds)->toBeLessThanOrEqual(3600);
});

// F-2.9.2-029 — daily and monthly counters gate eligibility too
it('respects daily and monthly quota headroom from the rollup table', function (): void {
    $daily = ownServer($this->customer, ['daily_quota' => 10, 'quota_exceeded_action' => 'next']);
    $monthly = ownServer($this->customer, ['monthly_quota' => 20, 'quota_exceeded_action' => 'next']);
    $open = ownServer($this->customer);

    DeliveryServerUsage::query()->create([
        'delivery_server_id' => $daily->id,
        'date' => Carbon::today()->toDateString(),
        'sent' => 10,
    ]);
    DeliveryServerUsage::query()->create([
        'delivery_server_id' => $monthly->id,
        'date' => Carbon::today()->subDays(3)->toDateString(),
        'sent' => 20,
    ]);

    $picked = [];

    for ($i = 0; $i < 20; $i++) {
        $picked[picker($i)->pickFor($this->customer)->server->id] = true;
    }

    expect($picked)->toBe([$open->id => true]);
});

// F-2.9.2-042 — hourly-quota-reached recovers once the window has headroom
it('recovers an hourly-quota-reached server in a fresh window', function (): void {
    $server = ownServer($this->customer, [
        'hourly_quota' => 100,
        'status' => DeliveryServerStatus::HourlyQuotaReached,
    ]);

    $result = picker()->pickFor($this->customer);

    expect($result->server?->id)->toBe($server->id)
        ->and($server->refresh()->status)->toBe(DeliveryServerStatus::Active);
});

it('returns an empty result when no server is eligible', function (): void {
    $result = picker()->pickFor($this->customer);

    expect($result->picked())->toBeFalse()
        ->and($result->shouldPause)->toBeFalse();
});

// F-2.9.2-028 — weighted rotation across the eligible pool (deterministic seed)
it('rotates across eligible servers by probability weight', function (): void {
    $heavy = ownServer($this->customer, ['probability' => 90]);
    $light = ownServer($this->customer, ['probability' => 10]);

    $counts = [$heavy->id => 0, $light->id => 0];
    $picker = picker(2024);

    for ($i = 0; $i < 300; $i++) {
        $counts[$picker->pickFor($this->customer)->server->id]++;
    }

    expect($counts[$heavy->id])->toBeGreaterThan($counts[$light->id] * 3)
        ->and($counts[$light->id])->toBeGreaterThan(0);
});
