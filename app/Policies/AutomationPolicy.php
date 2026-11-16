<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Automation;
use App\Models\Customer;
use App\Models\User;
use App\Services\Quota\QuotaService;

/**
 * Automations (§2.4) — customer-panel-only module gated by the D-42 group
 * permission `automations.enabled` plus row ownership (defense in depth on
 * top of the tenancy scope). The `max_automations` limit is enforced in
 * create() so the resource + duplicate action share one gate.
 */
class AutomationPolicy
{
    public const CUSTOMER_PERMISSION = 'automations.enabled';

    public const LIMIT_KEY = 'max_automations';

    public function __construct(private readonly QuotaService $quota) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer && $this->quota->allows($user, self::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, Automation $automation): bool
    {
        return $this->owns($user, $automation);
    }

    public function create(User|Customer $user): bool
    {
        if (! ($user instanceof Customer) || ! $this->viewAny($user)) {
            return false;
        }

        $current = Automation::withoutTenancy()->where('customer_id', $user->id)->count();

        return $this->quota->withinLimit($user, self::LIMIT_KEY, $current);
    }

    public function update(User|Customer $user, Automation $automation): bool
    {
        return $this->owns($user, $automation);
    }

    public function delete(User|Customer $user, Automation $automation): bool
    {
        return $this->owns($user, $automation);
    }

    public function restore(User|Customer $user, Automation $automation): bool
    {
        return $this->owns($user, $automation);
    }

    public function forceDelete(User|Customer $user, Automation $automation): bool
    {
        return $this->owns($user, $automation);
    }

    private function owns(User|Customer $user, Automation $automation): bool
    {
        return $user instanceof Customer
            && $this->viewAny($user)
            && (int) $automation->customer_id === (int) $user->id;
    }
}
