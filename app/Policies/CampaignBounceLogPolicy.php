<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampaignBounceLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Admin campaign bounce logs (spec §3.27 F-3.27-002, WP-17): read-only,
 * admin-guard only, `logs.view` permission (Super Admin via Gate::before).
 */
class CampaignBounceLogPolicy
{
    public const ADMIN_PERMISSION = 'logs.view';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        // logs.view (§3.27 global log viewer) OR servers.manage (WP-15's
        // per-bounce-server processing log pages render over this model).
        return $user instanceof User
            && ($this->checker->hasActivePermission($user, self::ADMIN_PERMISSION)
                || $this->checker->hasActivePermission($user, 'servers.manage'));
    }

    public function view(User|Customer $user, CampaignBounceLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return false; // written by the bounce processor only
    }

    public function update(User|Customer $user, CampaignBounceLog $log): bool
    {
        return false;
    }

    public function delete(User|Customer $user, CampaignBounceLog $log): bool
    {
        return false;
    }
}
