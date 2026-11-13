<?php

declare(strict_types=1);

namespace App\Services\Preferences;

use App\Models\Customer;
use App\Models\CustomerPreference;

/**
 * Per-customer key-value preferences (WP-10). The customers table is WP-01/02
 * territory, so preferences live in their own table. Values are plain strings;
 * callers own serialisation.
 */
class CustomerPreferences
{
    public function get(Customer $customer, string $key, ?string $default = null): ?string
    {
        $value = CustomerPreference::withoutTenancy()
            ->where('customer_id', $customer->id)
            ->where('key', $key)
            ->value('value');

        return $value ?? $default;
    }

    public function set(Customer $customer, string $key, ?string $value): void
    {
        CustomerPreference::withoutTenancy()->updateOrCreate(
            ['customer_id' => $customer->id, 'key' => $key],
            ['value' => $value],
        );
    }
}
