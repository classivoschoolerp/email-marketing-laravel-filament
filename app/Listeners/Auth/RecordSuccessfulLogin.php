<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Enums\LoginResult;
use App\Models\Customer;
use App\Models\LoginLog;
use Illuminate\Auth\Events\Login;

/**
 * Records successful sign-ins for both panel guards (spec §1.3 F-1.3-005)
 * and stamps the customer's last_login_at.
 */
class RecordSuccessfulLogin
{
    public function handle(Login $event): void
    {
        if (! in_array($event->guard, ['admin', 'customer'], true)) {
            return;
        }

        LoginLog::record(
            $event->guard,
            $event->user,
            $event->user->email ?? null,
            LoginResult::Success,
        );

        if ($event->user instanceof Customer) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        }
    }
}
