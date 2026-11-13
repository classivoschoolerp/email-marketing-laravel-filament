<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\TemplateCategory;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Category access (spec §2.5.2 / §3.14): customers manage their own rows only;
 * system categories (customer_id NULL) are admin-managed, read-only for
 * customers. Super Admin passes via Gate::before.
 */
class TemplateCategoryPolicy
{
    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true;
        }

        return $this->checker->hasActivePermission($user, EmailTemplatePolicy::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, TemplateCategory $category): bool
    {
        if ($user instanceof Customer) {
            return $category->isSystem() || (int) $category->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, EmailTemplatePolicy::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true;
        }

        return $this->checker->hasActivePermission($user, EmailTemplatePolicy::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, TemplateCategory $category): bool
    {
        if ($user instanceof Customer) {
            return ! $category->isSystem()
                && (int) $category->customer_id === (int) $user->id;
        }

        return $category->isSystem()
            && $this->checker->hasActivePermission($user, EmailTemplatePolicy::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, TemplateCategory $category): bool
    {
        return $this->update($user, $category);
    }
}
