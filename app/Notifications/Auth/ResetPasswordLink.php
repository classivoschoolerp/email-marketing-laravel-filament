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
 * Customer password reset link (spec §1.4 F-1.4-001) — tokenised URL with the
 * broker's expiry. Queued.
 *
 * WP-20 (spec §3.15 F-3.15-003): subject + body resolve through
 * SystemEmailRenderer (key `password-reset`, admin-overridable); the public
 * constructor API is unchanged.
 */
class ResetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $rendered = app(SystemEmailRenderer::class)->resolve('password-reset', [
            'RESET_URL' => $url,
            'EXPIRES_MINUTES' => (int) config('auth.passwords.customers.expire', 60),
        ]);

        return (new MailMessage)
            ->subject($rendered->subject)
            ->line(new HtmlString($rendered->html));
    }
}
