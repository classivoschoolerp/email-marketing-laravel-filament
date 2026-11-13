<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\EmailList;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * Lists (spec §2.2): customers manage their own lists; creation is gated by
 * the max_lists group quota (spec §0.3, QuotaService). Admin users need the
 * lists.* permissions (Super Admin passes via Gate::before).
 */
class EmailListPolicy
{
    public function __construct(
        private readonly RoleChecker $checker,
        private readonly QuotaService $quota,
    ) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function view(User|Customer $user, EmailList $list): bool
    {
        if ($user instanceof Customer) {
            return $list->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.view');
    }

    public function create(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            // max_lists quota (F-0.3-005); trashed lists pending purge still count.
            return $this->quota->withinLimit(
                $user,
                'max_lists',
                EmailList::withoutTenancy()->withTrashed()->where('customer_id', $user->id)->count(),
            );
        }

        return $this->checker->hasActivePermission($user, 'lists.create');
    }

    public function update(User|Customer $user, EmailList $list): bool
    {
        if ($user instanceof Customer) {
            return $list->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.update');
    }

    public function delete(User|Customer $user, EmailList $list): bool
    {
        if ($user instanceof Customer) {
            return $list->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'lists.delete');
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'lists.delete');
    }
}
