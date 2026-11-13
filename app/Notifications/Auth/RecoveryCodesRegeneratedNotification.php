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
 * Security notice sent whenever an account's 2FA recovery codes are
 * (re)generated (spec §3.15 F-3.15-005, WP-20). The codes themselves are
 * NEVER emailed — they are shown once in the UI; this is the "your previous
 * codes stopped working" alert. Queued. Rendered through SystemEmailRenderer
 * (key `twofa-recovery-codes`, admin-overridable).
 */
class RecoveryCodesRegeneratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(SystemEmailRenderer::class)->resolve('twofa-recovery-codes');

        return (new MailMessage)
            ->subject($rendered->subject)
            ->line(new HtmlString($rendered->html));
    }
}
