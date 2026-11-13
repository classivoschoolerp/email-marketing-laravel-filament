<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Enums\LoginResult;
use App\Models\Customer;
use App\Models\LoginLog;
use Illuminate\Auth\Events\Failed;

/**
 * Records failed sign-ins for both panel guards (spec §1.3 F-1.3-005).
 * A valid-credential attempt against a blocked/inactive account is recorded
 * as "blocked" (F-1.3-006).
 */
class RecordFailedLogin
{
    public function handle(Failed $event): void
    {
        if (! in_array($event->guard, ['admin', 'customer'], true)) {
            return;
        }

        $result = LoginResult::Failed;

        if ($event->user instanceof Customer && ! $event->user->status->canLogin()) {
            $result = LoginResult::Blocked;
        }

        $email = $event->credentials['email'] ?? ($event->user->email ?? null);

        LoginLog::record($event->guard, $event->user, is_string($email) ? $email : null, $result);
    }
}
