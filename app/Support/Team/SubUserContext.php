<?php

declare(strict_types=1);

namespace App\Support\Team;

use App\Models\Customer;
use App\Models\SubUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * Who is acting inside a customer session (WP-39, F-0.2-004).
 *
 * The customer guard always authenticates the tenant `Customer` — see
 * App\Auth\CustomerUserProvider — because that is what makes the D-05
 * global scope correct for a team member with no changes to the scope
 * itself. This class carries the second half of the identity: WHICH person
 * inside that tenant is driving the session.
 *
 * `null` means the account owner. A non-null value is re-validated on every
 * resolution against the authenticated customer, so a stale or tampered
 * session id can never make a member of tenant A act inside tenant B.
 *
 * Registered as a scoped singleton (see routes/modules/team.php), so jobs
 * and queued work start with an empty context — background work is the
 * account's, not a person's.
 */
final class SubUserContext
{
    public const SESSION_KEY = 'team.sub_user_id';

    private ?SubUser $subUser = null;

    private bool $resolved = false;

    public function set(?SubUser $subUser): void
    {
        $this->subUser = $subUser;
        $this->resolved = true;
    }

    /** Remember the acting member for the rest of the session. */
    public function remember(?SubUser $subUser): void
    {
        $this->set($subUser);

        try {
            if ($subUser === null) {
                Session::forget(self::SESSION_KEY);

                return;
            }

            Session::put(self::SESSION_KEY, $subUser->id);
        } catch (Throwable) {
            // No session (console/queue) — the in-memory value is enough.
        }
    }

    public function current(): ?SubUser
    {
        if ($this->resolved) {
            return $this->subUser;
        }

        $this->resolved = true;

        return $this->subUser = $this->fromSession();
    }

    public function check(): bool
    {
        return $this->current() !== null;
    }

    /** True when the session belongs to the account owner, not a member. */
    public function isOwner(): bool
    {
        return $this->current() === null;
    }

    public function forget(): void
    {
        $this->subUser = null;
        $this->resolved = true;

        try {
            Session::forget(self::SESSION_KEY);
        } catch (Throwable) {
            // no session
        }
    }

    /**
     * Re-hydrate from the session and re-validate against the authenticated
     * customer. Any mismatch resolves to "owner" — never to a member of a
     * different tenant.
     */
    private function fromSession(): ?SubUser
    {
        try {
            $id = Session::get(self::SESSION_KEY);
        } catch (Throwable) {
            return null;
        }

        if ($id === null) {
            return null;
        }

        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            return null;
        }

        try {
            $subUser = SubUser::query()
                ->where('id', (int) $id)
                ->where('customer_id', $customer->id)
                ->first();
        } catch (Throwable) {
            return null;
        }

        return ($subUser !== null && $subUser->canLogin()) ? $subUser : null;
    }
}
