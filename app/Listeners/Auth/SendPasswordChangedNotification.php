<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Notifications\Auth\PasswordChangedNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Notifications\Notifiable;

/**
 * Notify the account owner whenever their password changes (spec §1.4
 * F-1.4-003).
 */
class SendPasswordChangedNotification
{
    public function handle(PasswordReset $event): void
    {
        if (in_array(Notifiable::class, class_uses_recursive($event->user), true)
            || method_exists($event->user, 'notify')) {
            $event->user->notify(new PasswordChangedNotification);
        }
    }
}
