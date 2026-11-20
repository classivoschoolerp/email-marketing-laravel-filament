<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\SurveyField;
use App\Models\User;
use App\Services\Quota\QuotaService;

/**
 * Survey questions (spec F-2.8-003) — tenant-owned CRUD behind the same
 * `surveys.enabled` gate as the parent survey.
 */
class SurveyFieldPolicy
{
    public function __construct(private readonly QuotaService $quota) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            && $this->quota->allows($user, SurveyPolicy::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, SurveyField $field): bool
    {
        return $this->owns($user, $field);
    }

    public function create(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|Customer $user, SurveyField $field): bool
    {
        return $this->owns($user, $field);
    }

    public function delete(User|Customer $user, SurveyField $field): bool
    {
        return $this->owns($user, $field);
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    public function reorder(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    private function owns(User|Customer $user, SurveyField $field): bool
    {
        return $this->viewAny($user) && (int) $field->customer_id === (int) $user->id;
    }
}
