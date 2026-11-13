<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Campaign access rules (spec §2.3). Customers own their rows outright —
 * tenancy is enforced by CustomerScope, this policy is the second layer.
 * Admins need campaigns.manage (approval queue / oversight arrives with
 * later WPs); Super Admin passes via Gate::before.
 *
 * Wizard editability (edit-until-sending, §2.3.9) is a status-machine rule
 * enforced by the pages, not an ownership rule — pause/resume/block must
 * still authorize as `update` while a campaign is sending.
 */
class CampaignPolicy
{
    public const ADMIN_PERMISSION = 'campaigns.manage';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, Campaign $campaign): bool
    {
        if ($user instanceof Customer) {
            return (int) $campaign->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        // max_campaigns quota + type permissions enforced by CampaignQuota
        // in the creation path (mirrors the templates module).
        return $user instanceof Customer;
    }

    public function update(User|Customer $user, Campaign $campaign): bool
    {
        if ($user instanceof Customer) {
            return (int) $campaign->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    public function restore(User|Customer $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    public function forceDelete(User|Customer $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }
}
