<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\LandingPage;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * Landing pages (§2.6) — customers manage their own, gated by the §3.5 group
 * permission `landing_pages.enabled` plus row ownership (defense in depth on
 * top of the tenancy scope). The `max_landing_pages` limit is enforced in
 * create() so the resource and the duplicate action share one gate — the
 * FormPolicy shape, deliberately.
 *
 * WP-40 opened the ADMIN guard for the §3.10 cross-customer overview:
 * `landing_pages.view` sees every tenant's pages and their stats,
 * `landing_pages.update` may DISABLE one (unpublish — F-3.10-001 says
 * "disable", not "edit"), and `landing_pages.delete` may remove it. An
 * operator never gets an editing path into a tenant's page content.
 */
class LandingPagePolicy
{
    public const CUSTOMER_PERMISSION = 'landing_pages.enabled';

    public const ADMIN_VIEW = 'landing_pages.view';

    public const ADMIN_UPDATE = 'landing_pages.update';

    public const ADMIN_DELETE = 'landing_pages.delete';

    public const LIMIT_KEY = 'max_landing_pages';

    public function __construct(
        private readonly QuotaService $quota,
        private readonly RoleChecker $checker,
    ) {}

    public function viewAny(User|Customer $user): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_VIEW);
        }

        return $this->quota->allows($user, self::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, LandingPage $page): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_VIEW);
        }

        return $this->owns($user, $page);
    }

    public function create(User|Customer $user): bool
    {
        if (! ($user instanceof Customer) || ! $this->viewAny($user)) {
            return false;
        }

        // Trashed pages pending purge still occupy a slot (EmailListPolicy rule).
        $current = LandingPage::withoutTenancy()->withTrashed()->where('customer_id', $user->id)->count();

        return $this->quota->withinLimit($user, self::LIMIT_KEY, $current);
    }

    public function update(User|Customer $user, LandingPage $page): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_UPDATE);
        }

        return $this->owns($user, $page);
    }

    public function delete(User|Customer $user, LandingPage $page): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_DELETE);
        }

        return $this->owns($user, $page);
    }

    public function deleteAny(User|Customer $user): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_DELETE);
        }

        return $this->viewAny($user);
    }

    public function restore(User|Customer $user, LandingPage $page): bool
    {
        return $this->owns($user, $page);
    }

    public function forceDelete(User|Customer $user, LandingPage $page): bool
    {
        return $this->owns($user, $page);
    }

    private function owns(User|Customer $user, LandingPage $page): bool
    {
        return $user instanceof Customer
            && $this->viewAny($user)
            && (int) $page->customer_id === (int) $user->id;
    }
}
