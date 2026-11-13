<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampaignGroup;
use App\Models\Customer;
use App\Models\User;

/**
 * Campaign groups (spec §2.3.10) are purely customer-owned folders.
 */
class CampaignGroupPolicy
{
    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer;
    }

    public function view(User|Customer $user, CampaignGroup $group): bool
    {
        return $user instanceof Customer && (int) $group->customer_id === (int) $user->id;
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer;
    }

    public function update(User|Customer $user, CampaignGroup $group): bool
    {
        return $this->view($user, $group);
    }

    public function delete(User|Customer $user, CampaignGroup $group): bool
    {
        return $this->view($user, $group);
    }
}
