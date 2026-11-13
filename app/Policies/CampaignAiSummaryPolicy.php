<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CampaignAiSummary;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * AI campaign summaries (spec §2.15.8 F-2.15.8-008, WP-46) — tenant-owned.
 *
 * The permissions are the REPORT permissions, not campaign-edit ones: a
 * summary is a read of a report, and generating one must not require the
 * authority to change the campaign it describes.
 */
class CampaignAiSummaryPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'campaigns.view');
    }

    public function view(User|Customer $user, CampaignAiSummary $summary): bool
    {
        if ($user instanceof Customer) {
            return (int) $summary->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, 'campaigns.view');
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'campaigns.view');
    }

    public function update(User|Customer $user, CampaignAiSummary $summary): bool
    {
        return $this->view($user, $summary);
    }

    public function delete(User|Customer $user, CampaignAiSummary $summary): bool
    {
        if ($user instanceof Customer) {
            return (int) $summary->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, 'campaigns.delete');
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'campaigns.delete');
    }
}
