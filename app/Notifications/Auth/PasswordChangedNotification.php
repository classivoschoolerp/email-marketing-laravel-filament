<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Services\SystemEmails\SystemEmailRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * "Your password was changed" security notice (spec §1.4 F-1.4-003, also sent
 * from the profile change-password flow §2.15.1). Queued.
 *
 * WP-20 (spec §3.15 F-3.15-003): subject + body resolve through
 * SystemEmailRenderer (key `password-changed`, admin-overridable).
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(SystemEmailRenderer::class)->resolve('password-changed');

        return (new MailMessage)
            ->subject($rendered->subject)
            ->line(new HtmlString($rendered->html));
    }
}
