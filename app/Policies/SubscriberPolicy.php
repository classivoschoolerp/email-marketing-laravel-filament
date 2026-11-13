<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\Quota\QuotaService;
use App\Support\Roles\RoleChecker;

/**
 * Subscribers (spec §2.2.4–2.2.6): tenant-owned; creation is gated by the
 * max_subscribers group quota (spec §0.3).
 */
class SubscriberPolicy
{
    public function __construct(
        private readonly RoleChecker $checker,
        private readonly QuotaService $quota,
    ) {}

    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'subscribers.view');
    }

    public function view(User|Customer $user, Subscriber $subscriber): bool
    {
        if ($user instanceof Customer) {
            return $subscriber->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'subscribers.view');
    }

    public function create(User|Customer $user): bool
    {
        if ($user instanceof Customer) {
            return $this->quota->withinLimit(
                $user,
                'max_subscribers',
                Subscriber::withoutTenancy()->where('customer_id', $user->id)->count(),
            );
        }

        return $this->checker->hasActivePermission($user, 'subscribers.create');
    }

    public function update(User|Customer $user, Subscriber $subscriber): bool
    {
        if ($user instanceof Customer) {
            return $subscriber->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'subscribers.update');
    }

    public function delete(User|Customer $user, Subscriber $subscriber): bool
    {
        if ($user instanceof Customer) {
            return $subscriber->customer_id === $user->id;
        }

        return $this->checker->hasActivePermission($user, 'subscribers.delete');
    }

    public function deleteAny(User|Customer $user): bool
    {
        return $user instanceof Customer
            || $this->checker->hasActivePermission($user, 'subscribers.delete');
    }
}
