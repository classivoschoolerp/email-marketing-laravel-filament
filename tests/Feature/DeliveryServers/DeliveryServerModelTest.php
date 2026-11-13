<?php

declare(strict_types=1);

use App\Enums\DeliveryServerStatus;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DeliveryServer;
use App\Support\Tenancy\CurrentCustomer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// F-2.9.2-025..042 — schema: every spec column persists and round-trips
it('persists the full §2.9.2 column set', function (): void {
    $customer = Customer::factory()->create();

    $server = app(CurrentCustomer::class)->runAs($customer, fn () => DeliveryServer::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'smtp',
        'name' => 'Primary relay',
        'hostname' => 'smtp.example.com',
        'port' => 587,
        'protocol' => 'tls',
        'username' => 'mailer',
        'password' => 'secret-password',
        'api_key' => 'api-secret',
        'region' => 'eu-west-1',
        'from_email' => 'from@example.com',
        'from_name' => 'Sender',
        'reply_to_email' => 'reply@example.com',
        'force_from' => true,
        'force_reply_to' => true,
        'probability' => 60,
        'hourly_quota' => 100,
        'daily_quota' => 1000,
        'monthly_quota' => 10000,
        'quota_exceeded_action' => 'next',
        'max_connection_messages' => 50,
        'connection_timeout' => 15,
        'keep_alive' => false,
        'custom_headers' => ['X-Pool' => 'a'],
        'bounce_server_id' => 12,
        'tracking_domain_id' => 34,
        'sending_domain_id' => 56,
        'dkim_signing_enabled' => true,
        'dkim_private_key' => 'PEMDATA',
        'dkim_selector' => 's1',
        'warmup_plan_id' => 78,
        'locked' => false,
        'status' => 'inactive',
        'use_for' => 'all',
        'config' => ['virtual_mta' => 'vmta1'],
    ]));

    $fresh = DeliveryServer::withoutTenancy()->find($server->id);

    expect($fresh->uid)->not->toBeEmpty()
        ->and($fresh->customer_id)->toBe($customer->id)
        ->and($fresh->protocol->value)->toBe('tls')
        ->and($fresh->quota_exceeded_action->value)->toBe('next')
        ->and($fresh->status)->toBe(DeliveryServerStatus::Inactive)
        ->and($fresh->password)->toBe('secret-password')
        ->and($fresh->custom_headers)->toBe(['X-Pool' => 'a'])
        ->and($fresh->config)->toBe(['virtual_mta' => 'vmta1'])
        ->and($fresh->force_from)->toBeTrue()
        ->and($fresh->keep_alive)->toBeFalse()
        ->and($fresh->bounce_server_id)->toBe(12)
        ->and($fresh->tracking_domain_id)->toBe(34)
        ->and($fresh->sending_domain_id)->toBe(56)
        ->and($fresh->warmup_plan_id)->toBe(78)
        ->and($fresh->hourlyQuotaKey())->toBe('delivery-server:'.$fresh->id);
});

// Encrypted at rest (F-2.9.2-025/036, twofa_secret convention): raw DB bytes
// never contain the plaintext secrets; the casts decrypt them transparently.
it('stores password, api key and dkim key encrypted at rest', function (): void {
    $customer = Customer::factory()->create();

    $server = app(CurrentCustomer::class)->runAs($customer, fn () => DeliveryServer::factory()->create([
        'password' => 'smtp-plaintext',
        'api_key' => 'api-plaintext',
        'dkim_private_key' => 'dkim-plaintext',
    ]));

    $raw = DB::table('delivery_servers')->where('id', $server->id)->first();

    foreach (['password' => 'smtp-plaintext', 'api_key' => 'api-plaintext', 'dkim_private_key' => 'dkim-plaintext'] as $column => $plain) {
        expect($raw->{$column})->not->toBeNull()
            ->and($raw->{$column})->not->toContain($plain)
            ->and(Crypt::decryptString($raw->{$column}))->toBe($plain);
    }

    expect($server->refresh()->password)->toBe('smtp-plaintext')
        ->and($server->api_key)->toBe('api-plaintext')
        ->and($server->dkim_private_key)->toBe('dkim-plaintext');
});

// Tenancy (docs/ARCHITECTURE.md rule 3): global scope isolates tenants at model layer
it('scopes servers to the current tenant', function (): void {
    $mine = Customer::factory()->create();
    $other = Customer::factory()->create();

    $current = app(CurrentCustomer::class);
    $myServer = $current->runAs($mine, fn () => DeliveryServer::factory()->create(['customer_id' => $mine->id]));
    $current->runAs($other, fn () => DeliveryServer::factory()->create(['customer_id' => $other->id]));

    $current->set($mine);

    expect(DeliveryServer::query()->pluck('id')->all())->toBe([$myServer->id]);

    $current->set(null);
});

// F-2.9.2-037 — browsable: own rows + system rows assigned to my group
it('browsable shows own servers plus group-assigned system servers only', function (): void {
    $group = CustomerGroup::factory()->create();
    $otherGroup = CustomerGroup::factory()->create();
    $customer = Customer::factory()->create(['customer_group_id' => $group->id]);

    $current = app(CurrentCustomer::class);

    $own = $current->runAs($customer, fn () => DeliveryServer::factory()->create(['customer_id' => $customer->id]));
    $current->runAs($customer, function () use ($customer): void {
        DeliveryServer::factory()->create(); // another tenant's server
    });

    $forEveryone = $current->runAs(null, fn () => DeliveryServer::factory()->system()->create(['customer_groups' => null]));
    $forMyGroup = $current->runAs(null, fn () => DeliveryServer::factory()->system()->create(['customer_groups' => [$group->id]]));
    $forOtherGroup = $current->runAs(null, fn () => DeliveryServer::factory()->system()->create(['customer_groups' => [$otherGroup->id]]));

    $visible = $current->runAs(
        $customer,
        fn () => DeliveryServer::query()->browsable()->pluck('id')->sort()->values()->all(),
    );

    expect($visible)->toBe(collect([$own->id, $forEveryone->id, $forMyGroup->id])->sort()->values()->all())
        ->and($forOtherGroup->isVisibleToCustomer($customer))->toBeFalse()
        ->and($forMyGroup->isVisibleToCustomer($customer))->toBeTrue()
        ->and($forEveryone->isSystem())->toBeTrue();
});

it('soft deletes servers', function (): void {
    $customer = Customer::factory()->create();

    $server = app(CurrentCustomer::class)->runAs($customer, fn () => DeliveryServer::factory()->create());
    $server->delete();

    expect(DeliveryServer::withoutTenancy()->find($server->id))->toBeNull()
        ->and(DeliveryServer::withoutTenancy()->withTrashed()->find($server->id))->not->toBeNull();
});

// F-2.9.2-029 usage counters table
it('rolls up daily usage per server', function (): void {
    $customer = Customer::factory()->create();
    $server = app(CurrentCustomer::class)->runAs($customer, fn () => DeliveryServer::factory()->create());

    $usage = app(\App\Sending\ServerUsage::class);
    $usage->recordSend($server, 3);
    $usage->recordSend($server, 2);
    $usage->recordFailure($server);

    $row = \App\Models\DeliveryServerUsage::query()->where('delivery_server_id', $server->id)->first();

    expect($row->sent)->toBe(5)
        ->and($row->failed)->toBe(1)
        ->and($usage->usedToday($server))->toBe(5)
        ->and($usage->usedThisMonth($server))->toBe(5)
        ->and($usage->usedThisHour($server))->toBe(5)
        ->and($server->refresh()->last_used_at)->not->toBeNull();
});
