<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\InboxPreviewRun;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Inbox rendering preview runs (spec §2.3.5 F-2.3.5-006, WP-46) —
 * tenant-owned.
 *
 * A run carries a rendered copy of a campaign, so the permissions are the
 * CAMPAIGN permissions: whoever may see the campaign may see its previews.
 */
class InboxPreviewRunPolicy
{
    public const ADMIN_PERMISSION = 'campaigns.manage';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, InboxPreviewRun $run): bool
    {
        if ($user instanceof Customer) {
            return (int) $run->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, InboxPreviewRun $run): bool
    {
        return $this->view($user, $run);
    }

    public function delete(User|Customer $user, InboxPreviewRun $run): bool
    {
        return $this->view($user, $run);
    }
}
