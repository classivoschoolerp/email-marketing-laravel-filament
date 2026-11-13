<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BounceServer;
use App\Models\Customer;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * Bounce server access (spec §2.9.4 / §3.13.2, WP-15). Admin users need
 * servers.manage (Super Admin passes via Gate::before). Customers may manage
 * their OWN unlocked rows, gated by the servers.bounce.add permission and
 * max_bounce_servers quota (D-42 matrix keys) — global rows (customer_id
 * NULL) are admin-only.
 */
class BounceServerPolicy
{
    public const ADMIN_PERMISSION = 'servers.manage';

    public const ADD_PERMISSION = 'servers.bounce.add';

    public const COUNT_KEY = 'max_bounce_servers';

    public function __construct(
        private readonly RoleChecker $checker,
        private readonly QuotaService $quotas,
    ) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return true;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, BounceServer $server): bool
    {
        if ($user instanceof Customer) {
            return ! $server->isSystem() && (int) $server->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function create(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return $this->quotas->allows($user, self::ADD_PERMISSION)
                && $this->quotas->withinLimit(
                    $user,
                    self::COUNT_KEY,
                    BounceServer::withoutTenancy()->where('customer_id', $user->id)->count(),
                );
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, BounceServer $server): bool
    {
        if ($user instanceof Customer) {
            return $server->isEditableByCustomer($user);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, BounceServer $server): bool
    {
        return $this->update($user, $server);
    }
}
