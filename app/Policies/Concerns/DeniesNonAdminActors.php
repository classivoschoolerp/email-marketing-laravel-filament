<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Admin-module policies only ever authorize admin users: any other actor
 * (customer-guard models, API principals) is denied outright before the
 * typed policy methods run (spec §3.3 F-3.3-002). Super Admin still passes
 * through the global Gate::before, which runs ahead of policy before hooks.
 */
trait DeniesNonAdminActors
{
    public function before(mixed $user, string $ability): ?bool
    {
        return $user instanceof User ? null : false;
    }
}
