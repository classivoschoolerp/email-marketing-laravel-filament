<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\Survey;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * Surveys (§2.8) — customers manage their own, gated by the §3.5 group
 * permission `surveys.enabled` plus row ownership. The `max_surveys` limit
 * is enforced in create().
 *
 * WP-40 opened the ADMIN guard for the §3.9 cross-customer overview: an
 * operator with `surveys.view` may see every tenant's surveys and their
 * stats, and `surveys.delete` may remove one. Admins deliberately get NO
 * create/update path — §3.9 F-3.9-001 is "all surveys across customers, with
 * stats access, delete", and an operator editing a tenant's questionnaire
 * would silently rewrite their data.
 */
class SurveyPolicy
{
    public const CUSTOMER_PERMISSION = 'surveys.enabled';

    public const ADMIN_VIEW = 'surveys.view';

    public const ADMIN_DELETE = 'surveys.delete';

    public const LIMIT_KEY = 'max_surveys';

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

    public function view(User|Customer $user, Survey $survey): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_VIEW);
        }

        return $this->owns($user, $survey);
    }

    public function create(User|Customer $user): bool
    {
        if (! ($user instanceof Customer) || ! $this->viewAny($user)) {
            return false;
        }

        $current = Survey::withoutTenancy()->withTrashed()->where('customer_id', $user->id)->count();

        return $this->quota->withinLimit($user, self::LIMIT_KEY, $current);
    }

    public function update(User|Customer $user, Survey $survey): bool
    {
        return $this->owns($user, $survey);
    }

    public function delete(User|Customer $user, Survey $survey): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_DELETE);
        }

        return $this->owns($user, $survey);
    }

    public function deleteAny(User|Customer $user): bool
    {
        if ($user instanceof User) {
            return $this->checker->hasActivePermission($user, self::ADMIN_DELETE);
        }

        return $this->viewAny($user);
    }

    public function restore(User|Customer $user, Survey $survey): bool
    {
        return $this->owns($user, $survey);
    }

    public function forceDelete(User|Customer $user, Survey $survey): bool
    {
        return $this->owns($user, $survey);
    }

    private function owns(User|Customer $user, Survey $survey): bool
    {
        return $user instanceof Customer
            && $this->viewAny($user)
            && (int) $survey->customer_id === (int) $user->id;
    }
}
