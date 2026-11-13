<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BounceRule;
use App\Models\Customer;
use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Bounce classification rules are operator-level config (spec §2.9.4
 * F-2.9.4-006) — admin-only (servers.manage), customers never touch them.
 */
class BounceRulePolicy
{
    public const ADMIN_PERMISSION = 'servers.manage';

    public function __construct(private readonly RoleChecker $checker) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof User
            && $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, BounceRule $rule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|Customer $user, BounceRule $rule): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User|Customer $user, BounceRule $rule): bool
    {
        return $this->viewAny($user);
    }
}
