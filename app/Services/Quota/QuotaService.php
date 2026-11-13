<?php

declare(strict_types=1);

namespace App\Services\Quota;

use App\Models\Customer;
use App\Models\CustomerGroup;

/**
 * Reads the per-customer-group quota + feature-permission matrices (spec §0.3
 * F-0.3-004/005). Every quota and feature toggle hangs off the customer group;
 * missing rows fall back to config('emp.quotas.defaults') /
 * config('emp.group_permissions.*').
 *
 * WP-03 (spec §3.4 F-3.4-008/009): per-customer override rows
 * (customer_limits / customer_permissions) are checked BEFORE the group
 * matrix. The public API is unchanged — limitFor()/allows() simply consult
 * the override first.
 */
class QuotaService
{
    public const UNLIMITED = null;

    /**
     * Quota limit for a group. null = unlimited.
     */
    public function limitForGroup(?CustomerGroup $group, string $key): ?int
    {
        if ($group !== null) {
            $row = $group->limits()->where('key', $key)->first();

            if ($row !== null) {
                return $row->value;
            }
        }

        $default = config('emp.quotas.defaults.'.$key);

        return $default === null ? self::UNLIMITED : (int) $default;
    }

    public function limitFor(Customer $customer, string $key): ?int
    {
        // Per-customer override wins over the group matrix (F-3.4-008).
        $override = $customer->limitOverrides()->where('key', $key)->first();

        if ($override !== null) {
            return $override->value;
        }

        return $this->limitForGroup($customer->group, $key);
    }

    /**
     * Would the customer stay within the quota after adding $adding more?
     */
    public function withinLimit(Customer $customer, string $key, int $currentUsage, int $adding = 1): bool
    {
        $limit = $this->limitFor($customer, $key);

        if ($limit === self::UNLIMITED) {
            return true;
        }

        return ($currentUsage + $adding) <= $limit;
    }

    /**
     * Feature toggle for a group (permission matrix). Missing rows fall back
     * to config defaults, then to the global default_allowed flag.
     */
    public function groupAllows(?CustomerGroup $group, string $key): bool
    {
        if ($group !== null) {
            $row = $group->featurePermissions()->where('key', $key)->first();

            if ($row !== null) {
                return $row->allowed;
            }
        }

        return (bool) config(
            'emp.group_permissions.defaults.'.$key,
            config('emp.group_permissions.default_allowed', true)
        );
    }

    public function allows(Customer $customer, string $key): bool
    {
        // Per-customer override wins over the group matrix (F-3.4-009).
        $override = $customer->permissionOverrides()->where('key', $key)->first();

        if ($override !== null) {
            return $override->allowed;
        }

        return $this->groupAllows($customer->group, $key);
    }
}
