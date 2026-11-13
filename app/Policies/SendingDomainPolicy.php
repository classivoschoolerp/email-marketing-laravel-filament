<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\SendingDomain;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * Sending domain access (spec §2.9.7 / §3.13.5, WP-15). Admin users need
 * domains.manage. Customers manage their OWN rows, creation gated by the
 * domains.sending.add permission + max_sending_domains quota (D-42 matrix
 * keys, already catalogued in CustomerGroupMatrix).
 */
class SendingDomainPolicy
{
    public const ADMIN_PERMISSION = 'domains.manage';

    public const ADD_PERMISSION = 'domains.sending.add';

    public const COUNT_KEY = 'max_sending_domains';

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

    public function view(User|Customer $user, SendingDomain $domain): bool
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
                    SendingDomain::withoutTenancy()->where('customer_id', $user->id)->count(),
                );
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function update(User|Customer $user, SendingDomain $domain): bool
    {
        if ($user instanceof Customer) {
            return (int) $domain->customer_id === (int) $user->id;
        }

        return $this->checker->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function delete(User|Customer $user, SendingDomain $domain): bool
    {
        return $this->update($user, $domain);
    }
}
