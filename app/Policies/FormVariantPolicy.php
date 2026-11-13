<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\FormVariant;
use App\Models\User;
use App\Services\Quota\QuotaService;

/**
 * A/B arms of a §2.7 form (spec F-2.7-013) — tenant-owned CRUD behind the same
 * `forms.enabled` gate as the parent form, exactly like {@see FormFieldPolicy}.
 */
class FormVariantPolicy
{
    public function __construct(private readonly QuotaService $quota) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            && $this->quota->allows($user, FormPolicy::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, FormVariant $variant): bool
    {
        return $this->owns($user, $variant);
    }

    public function create(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|Customer $user, FormVariant $variant): bool
    {
        return $this->owns($user, $variant);
    }

    public function delete(User|Customer $user, FormVariant $variant): bool
    {
        return $this->owns($user, $variant);
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    private function owns(User|Customer $user, FormVariant $variant): bool
    {
        return $this->viewAny($user) && (int) $variant->customer_id === (int) $user->id;
    }
}
