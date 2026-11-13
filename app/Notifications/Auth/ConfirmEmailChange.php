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
 * Re-verification link sent to the *new* address on an email change
 * (spec §2.15.1 F-2.15.1-005). Sent on-demand; queued.
 *
 * WP-20 (spec §3.15 F-3.15-004): subject + body resolve through
 * SystemEmailRenderer (key `email-change-verification`, admin-overridable);
 * the public constructor API is unchanged.
 */
class ConfirmEmailChange extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(SystemEmailRenderer::class)->resolve('email-change-verification', [
            'CONFIRM_URL' => $this->url,
            'EXPIRES_HOURS' => (int) config('emp.email_change.ttl_hours', 48),
        ]);

        return (new MailMessage)
            ->subject($rendered->subject)
            ->line(new HtmlString($rendered->html));
    }
}
