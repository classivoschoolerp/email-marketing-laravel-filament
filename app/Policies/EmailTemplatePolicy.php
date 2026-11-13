<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Template access rules (spec §2.5 / §3.14).
 *
 * Customers: full control of their own rows; system gallery rows are strictly
 * read-only — copying happens through TemplateCopier which creates a NEW row
 * owned by the customer. Admins need the templates.manage permission and may
 * only mutate system rows (customer templates stay customer-owned); Super
 * Admin passes via Gate::before.
 */
class EmailTemplatePolicy
{
    public const ADMIN_PERMISSION = 'templates.manage';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, EmailTemplate $template): bool
    {
        if ($user instanceof Customer) {
            return $template->isVisibleToCustomer($user);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true; // max_templates quota enforced by TemplateQuota in the creation paths
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, EmailTemplate $template): bool
    {
        if ($user instanceof Customer) {
            // Gallery/system rows are read-only for customers — copy, never edit.
            return ! $template->isSystem()
                && (int) $template->customer_id === (int) $user->id;
        }

        return $template->isSystem()
            && $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, EmailTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    public function restore(User|Customer $user, EmailTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    public function forceDelete(User|Customer $user, EmailTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}
