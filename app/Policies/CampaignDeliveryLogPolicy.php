<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampaignDeliveryLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Admin campaign delivery logs (spec §3.27 F-3.27-001, WP-17): read-only,
 * admin-guard only, gated by the `logs.view` permission (Super Admin passes
 * via Gate::before). Customers never touch this global view — their
 * per-campaign delivery tab (§2.3.8) is scoped by CampaignPolicy instead.
 */
class CampaignDeliveryLogPolicy
{
    public const ADMIN_PERMISSION = 'logs.view';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof User
            && $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, CampaignDeliveryLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return false; // written by the sending engine only
    }

    public function update(User|Customer $user, CampaignDeliveryLog $log): bool
    {
        return false;
    }

    public function delete(User|Customer $user, CampaignDeliveryLog $log): bool
    {
        return false; // retention is logs:prune's job (F-3.27-015)
    }
}
