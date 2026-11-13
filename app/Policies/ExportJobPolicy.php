<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\ExportJob;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Export history + download access (spec §2.2.8). A customer only ever
 * touches their own exports — the signed URL alone is NOT enough (the
 * download controller authorizes on top of the signature).
 */
class ExportJobPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function view(User|Customer $user, ExportJob $export): bool
    {
        if ($user instanceof Customer) {
            return $export->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function download(User|Customer $user, ExportJob $export): bool
    {
        return $this->view($user, $export);
    }
}
