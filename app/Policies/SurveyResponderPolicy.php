<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\SurveyResponder;
use App\Models\User;
use App\Services\Quota\QuotaService;

/**
 * Survey responses (spec F-2.8-005) are append-only: readable and deletable
 * (GDPR erasure) by the owning tenant, never editable.
 */
class SurveyResponderPolicy
{
    public function __construct(private readonly QuotaService $quota) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            && $this->quota->allows($user, SurveyPolicy::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, SurveyResponder $responder): bool
    {
        return $this->owns($user, $responder);
    }

    public function create(User|Customer $user): bool
    {
        return false;
    }

    public function update(User|Customer $user, SurveyResponder $responder): bool
    {
        return false;
    }

    public function delete(User|Customer $user, SurveyResponder $responder): bool
    {
        return $this->owns($user, $responder);
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    private function owns(User|Customer $user, SurveyResponder $responder): bool
    {
        return $this->viewAny($user) && (int) $responder->customer_id === (int) $user->id;
    }
}
