<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Sending\DriverRegistry;
use App\Services\Automations\GraphPublisher;
use App\Support\Tenancy\CurrentCustomer;
use Filament\Facades\Filament;
use Tests\Fakes\RecordingSendingDriver;

/**
 * Shared builders for the WP-21 automation tests.
 */
final class AutomationFixtures
{
    /** Log in to the customer panel + bind tenancy (house test pattern). */
    public static function actingAsCustomer(?Customer $customer = null): Customer
    {
        $customer ??= Customer::factory()->create();

        test()->actingAs($customer, 'customer');
        Filament::setCurrentPanel(Filament::getPanel('customer'));
        app(CurrentCustomer::class)->set($customer);

        return $customer;
    }

    /** @return array{0: EmailList, 1: Subscriber} */
    public static function listWithSubscriber(Customer $customer, string $email = 'anna@example.com'): array
    {
        $list = EmailList::factory()->create(['customer_id' => $customer->id]);

        $subscriber = Subscriber::factory()->create([
            'list_id' => $list->id,
            'customer_id' => $customer->id,
            'email' => $email,
        ]);

        return [$list, $subscriber];
    }

    /**
     * Canvas node array.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $edges
     * @return array<string, mixed>
     */
    public static function node(string $uid, AutomationNodeType $type, array $config = [], array $edges = []): array
    {
        return [
            'uid' => $uid,
            'type' => $type->value,
            'label' => null,
            'config' => $config,
            'edges' => $edges,
        ];
    }

    /**
     * Create + publish + activate an automation from canvas nodes.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $attributes
     */
    public static function automation(Customer $customer, array $nodes, array $attributes = [], bool $activate = true): Automation
    {
        $automation = Automation::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'canvas' => ['nodes' => $nodes],
        ], $attributes));

        app(GraphPublisher::class)->publish($automation);
        $automation->refresh();

        if ($activate) {
            $automation->forceFill(['status' => AutomationStatus::Active])->save();
        }

        return $automation;
    }

    /** Register the recording fake driver + a matching delivery server. */
    public static function fakeDeliveryServer(Customer $customer): RecordingSendingDriver
    {
        $driver = new RecordingSendingDriver('smtp');
        app(DriverRegistry::class)->register('smtp', $driver);

        DeliveryServer::factory()->create([
            'customer_id' => $customer->id,
            'type' => 'smtp',
        ]);

        return $driver;
    }
}
