<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\SpintaxSnippet;
use App\Models\User;

/**
 * Spintax snippet library (spec §2.3.3 F-2.3.3-023) — customer-owned rows.
 */
class SpintaxSnippetPolicy
{
    public function viewAny(User|Customer $user): bool
    {
        return $user instanceof Customer;
    }

    public function view(User|Customer $user, SpintaxSnippet $snippet): bool
    {
        return $user instanceof Customer && (int) $snippet->customer_id === (int) $user->id;
    }

    public function create(User|Customer $user): bool
    {
        return $user instanceof Customer;
    }

    public function update(User|Customer $user, SpintaxSnippet $snippet): bool
    {
        return $this->view($user, $snippet);
    }

    public function delete(User|Customer $user, SpintaxSnippet $snippet): bool
    {
        return $this->view($user, $snippet);
    }
}
