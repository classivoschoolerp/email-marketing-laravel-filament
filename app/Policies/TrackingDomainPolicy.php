<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\TrackingDomain;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * Tracking domain access (spec §2.9.8 / §3.13.6, WP-15) — same shape as
 * SendingDomainPolicy with domains.tracking.add / max_tracking_domains keys.
 */
class TrackingDomainPolicy
{
    public const ADMIN_PERMISSION = 'domains.manage';

    public const ADD_PERMISSION = 'domains.tracking.add';

    public const COUNT_KEY = 'max_tracking_domains';

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

    public function view(User|Customer $user, TrackingDomain $domain): bool
    {
        if ($user instanceof Customer) {
            return (int) $domain->customer_id === (int) $user->id;
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
                    TrackingDomain::withoutTenancy()->where('customer_id', $user->id)->count(),
                );
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, TrackingDomain $domain): bool
    {
        if ($user instanceof Customer) {
            return (int) $domain->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, TrackingDomain $domain): bool
    {
        return $this->update($user, $domain);
    }
}
