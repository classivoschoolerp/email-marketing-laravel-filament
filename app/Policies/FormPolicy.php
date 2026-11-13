<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\Form;
use App\Models\User;
use App\Services\Quota\QuotaService;

/**
 * Forms & popups (§2.7) — customer-panel-only module gated by the §3.5 group
 * permission `forms.enabled` plus row ownership (defense in depth on top of
 * the tenancy scope). The `max_forms` limit is enforced in create() so the
 * resource and the duplicate action share one gate.
 */
class FormPolicy
{
    public const CUSTOMER_PERMISSION = 'forms.enabled';

    public const LIMIT_KEY = 'max_forms';

    public function __construct(private readonly QuotaService $quota) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer && $this->quota->allows($user, self::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    public function create(User|Customer $user): bool
    {
        if (! ($user instanceof Customer) || ! $this->viewAny($user)) {
            return false;
        }

        // Trashed forms pending purge still occupy a slot (EmailListPolicy rule).
        $current = Form::withoutTenancy()->withTrashed()->where('customer_id', $user->id)->count();

        return $this->quota->withinLimit($user, self::LIMIT_KEY, $current);
    }

    public function update(User|Customer $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    public function delete(User|Customer $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    public function restore(User|Customer $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    public function forceDelete(User|Customer $user, Form $form): bool
    {
        return $this->owns($user, $form);
    }

    private function owns(User|Customer $user, Form $form): bool
    {
        return $user instanceof Customer
            && $this->viewAny($user)
            && (int) $form->customer_id === (int) $user->id;
    }
}
