<?php

declare(strict_types=1);

namespace App\Services\Sending;

use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Services\Quota\QuotaService;
use App\Services\Settings\SettingsReader;
use App\Support\Quota\CustomerGroupMatrix;
use Illuminate\Validation\ValidationException;

/**
 * Gate for customer-created delivery servers (spec §2.9.2, WP-14).
 *
 * Create gate (all must pass):
 *   1. global "force system servers only" kill-switch off (§3.25.4)
 *   2. group/customer permission `servers.delivery.add` (D-42 matrix)
 *   3. `max_delivery_servers` count quota (QuotaService: customer override →
 *      group matrix → config default; trashed rows pending purge still count)
 *
 * Type whitelist chain (D-21/D-42, checked per driver key):
 *   customer override (customer_permissions `servers.type.{key}`) →
 *   group matrix (customer_group_permissions) → config default, AND the
 *   global §3.25.4 whitelist (CustomerSettings.allowed_sending_server_types).
 */
class DeliveryServerQuota
{
    public const COUNT_KEY = 'max_delivery_servers';

    public const ADD_PERMISSION = 'servers.delivery.add';

    public function __construct(private readonly QuotaService $quotas) {}

    public function currentUsage(Customer $customer): int
    {
        return DeliveryServer::withoutTenancy()
            ->withTrashed()
            ->where('customer_id', $customer->id)
            ->count();
    }

    public function canCreate(Customer $customer, int $adding = 1): bool
    {
        if (SettingsReader::customer()->forceSystemServersOnly()) {
            return false;
        }

        if (! $this->quotas->allows($customer, self::ADD_PERMISSION)) {
            return false;
        }

        return $this->quotas->withinLimit(
            $customer,
            self::COUNT_KEY,
            $this->currentUsage($customer),
            $adding,
        );
    }

    /** Full whitelist chain for one driver key. */
    public function typeAllowed(Customer $customer, string $type): bool
    {
        return SettingsReader::customer()->sendingServerTypeAllowed($type)
            && $this->quotas->allows($customer, CustomerGroupMatrix::serverTypeKey($type));
    }

    /**
     * @param  list<string>  $types  candidate driver keys (usually registry keys)
     * @return list<string>
     */
    public function allowedTypes(Customer $customer, array $types): array
    {
        return array_values(array_filter(
            $types,
            fn (string $type): bool => $this->typeAllowed($customer, $type),
        ));
    }

    /** @throws ValidationException */
    public function assertCanCreate(Customer $customer, string $type): void
    {
        if (! $this->canCreate($customer)) {
            throw ValidationException::withMessages([
                'name' => __('sending.errors.cannot_create'),
            ]);
        }

        if (! $this->typeAllowed($customer, $type)) {
            throw ValidationException::withMessages([
                'type' => __('sending.errors.type_not_allowed'),
            ]);
        }
    }
}
