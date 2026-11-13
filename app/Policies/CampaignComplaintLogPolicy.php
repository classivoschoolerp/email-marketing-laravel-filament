<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampaignComplaintLog;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Admin complaint/FBL logs (spec §3.27 F-3.27-003, WP-17): read-only,
 * admin-guard only, `logs.view` permission (Super Admin via Gate::before).
 */
class CampaignComplaintLogPolicy
{
    public const ADMIN_PERMISSION = 'logs.view';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        // logs.view (§3.27) OR servers.manage (WP-15 per-FBL-server log pages).
        return $user instanceof User
            && ($this->checker->hasActivePermission($user, self::ADMIN_PERMISSION)
                || $this->checker->hasActivePermission($user, 'servers.manage'));
    }

    public function view(User|Customer $user, CampaignComplaintLog $log): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return false; // written by the FBL processor only
    }

    public function update(User|Customer $user, CampaignComplaintLog $log): bool
    {
        return false;
    }

    public function delete(User|Customer $user, CampaignComplaintLog $log): bool
    {
        return false;
    }
}
