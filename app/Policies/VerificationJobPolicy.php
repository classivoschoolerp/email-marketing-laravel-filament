<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Models\VerificationJob;

/**
 * Verification runs (§2.9.12) — customer-panel module. Every customer may
 * run verifications on their own data (credits are the throttle, D-42
 * `verification_credits`); rows are tenant-fenced by ownership on top of
 * the global scope. No admin UI.
 */
class VerificationJobPolicy
{
    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer;
    }

    public function view(User|Customer $user, VerificationJob $job): bool
    {
        return $this->owns($user, $job);
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer;
    }

    public function delete(User|Customer $user, VerificationJob $job): bool
    {
        return $this->owns($user, $job);
    }

    public function download(User|Customer $user, VerificationJob $job): bool
    {
        return $this->owns($user, $job);
    }

    private function owns(User|Customer $user, VerificationJob $job): bool
    {
        return $user instanceof Customer && $job->customer_id === $user->id;
    }
}
