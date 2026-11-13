<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\Roles\RoleChecker;
use Illuminate\Database\Eloquent\Model;

/**
 * §3.23 location-services reference data (countries/zones) is install-wide
 * operator data, so it shares one coarse admin permission —
 * `location.manage` — in the same spirit as `blacklist.manage` /
 * `billing.manage`. Super Admin passes through the global Gate::before.
 */
trait AdminLocationPolicy
{
    use DeniesNonAdminActors;

    public const ADMIN_PERMISSION = 'location.manage';

    public function viewAny(User $user): bool
    {
        return app(RoleChecker::class)->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
