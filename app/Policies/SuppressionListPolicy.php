<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\SuppressionList;
use App\Models\User;
use App\Services\Quota\QuotaService;

/**
 * Suppression lists (§2.9.11) — a customer-panel-only module gated by the
 * D-42 group key `suppression.manage` plus row ownership (defense in depth
 * on top of the tenancy scope). No admin UI exists, so admin-guard users
 * are denied.
 */
class SuppressionListPolicy
{
    public const CUSTOMER_PERMISSION = 'suppression.manage';

    public function __construct(private readonly QuotaService $quota) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer && $this->quota->allows($user, self::CUSTOMER_PERMISSION);
    }

    public function view(User|Customer $user, SuppressionList $list): bool
    {
        return $this->owns($user, $list);
    }

    public function create(User|Customer $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User|Customer $user, SuppressionList $list): bool
    {
        return $this->owns($user, $list);
    }

    public function delete(User|Customer $user, SuppressionList $list): bool
    {
        return $this->owns($user, $list);
    }

    public function download(User|Customer $user, SuppressionList $list): bool
    {
        return $this->owns($user, $list);
    }

    private function owns(User|Customer $user, SuppressionList $list): bool
    {
        return $user instanceof Customer
            && $this->viewAny($user)
            && $list->customer_id === $user->id;
    }
}
