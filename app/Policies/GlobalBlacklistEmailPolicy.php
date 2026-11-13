<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\GlobalBlacklistEmail;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Services\Settings\SettingsReader;
use App\Support\Roles\RoleChecker;

/**
 * Global blacklist rows (§2.9.10 customer view / §3.17 admin module).
 *
 * Customers: gated by the D-42 group key `blacklist.manage`; they see their
 * own rows always and everyone's rows when the operator runs the blacklist
 * in shared mode (F-3.25.5-004) — but may only DELETE rows they own, in
 * either mode (a tenant must never remove another tenant's protection).
 * Admins: the coarse `blacklist.manage` admin permission covers everything.
 */
class GlobalBlacklistEmailPolicy
{
    public const ADMIN_PERMISSION = 'blacklist.manage';

    public const CUSTOMER_PERMISSION = 'blacklist.manage';

    public function __construct(
        private readonly RoleChecker $checker,
        private readonly QuotaService $quota,
    ) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return $this->quota->allows($user, self::CUSTOMER_PERMISSION);
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User|Customer $user, GlobalBlacklistEmail $entry): bool
    {
        if ($user instanceof Customer) {
            return $this->viewAny($user)
                && ($entry->customer_id === $user->id || SettingsReader::blacklist()->sharedAcrossCustomers());
        }

        return $this->viewAny($user);
    }

    public function create(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User|Customer $user, GlobalBlacklistEmail $entry): bool
    {
        if ($user instanceof Customer) {
            return $this->viewAny($user) && $entry->customer_id === $user->id;
        }

        return $this->viewAny($user);
    }
}
