<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\FblServer;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * FBL server access (spec §2.9.5 / §3.13.3, WP-15) — same shape as
 * BounceServerPolicy with the servers.fbl.add / max_fbl_servers matrix keys.
 */
class FblServerPolicy
{
    public const ADMIN_PERMISSION = 'servers.manage';

    public const ADD_PERMISSION = 'servers.fbl.add';

    public const COUNT_KEY = 'max_fbl_servers';

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

    public function view(User|Customer $user, FblServer $server): bool
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
                    FblServer::withoutTenancy()->where('customer_id', $user->id)->count(),
                );
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, FblServer $server): bool
    {
        if ($user instanceof Customer) {
            return $server->isEditableByCustomer($user);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, FblServer $server): bool
    {
        return $this->update($user, $server);
    }
}
