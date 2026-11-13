<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\Quota\QuotaService;

/**
 * Form submissions (spec §2.7) are an append-only record: readable and
 * deletable by the owning tenant, never editable — a lead's own words are
 * not something the panel gets to rewrite.
 */
class FormSubmissionPolicy
{
    public function __construct(private readonly QuotaService $quota) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            && $this->quota->allows($user, FormPolicy::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, FormSubmission $submission): bool
    {
        return $this->owns($user, $submission);
    }

    public function create(User|Customer $user): bool
    {
        return false;
    }

    public function update(User|Customer $user, FormSubmission $submission): bool
    {
        return false;
    }

    public function delete(User|Customer $user, FormSubmission $submission): bool
    {
        return $this->owns($user, $submission);
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    private function owns(User|Customer $user, FormSubmission $submission): bool
    {
        return $this->viewAny($user) && (int) $submission->customer_id === (int) $user->id;
    }
}
