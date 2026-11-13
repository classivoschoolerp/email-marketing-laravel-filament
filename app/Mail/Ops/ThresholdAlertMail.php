<?php

declare(strict_types=1);

namespace App\Mail\Ops;

use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §6.6 F-6.6-005 threshold-breach email (WP-24). Deliberately does NOT go
 * through SystemEmailRenderer: §3.15 system-email templates are customer-
 * facing lifecycle mail, while this is an operator alert that must still work
 * when the settings/template tables are unreadable (that is often *why* it is
 * firing). Strings live in lang/en/ops.php.
 *
 * Promoted constructor properties must not be readonly on a queued Mailable
 * (D-46).
 */
class ThresholdAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /** @var array<string, mixed> */
    public array $check;

    /** @param array<string, mixed> $check */
    public function __construct(array $check)
    {
        $this->check = $check;
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('ops.health.alert_title', [
            'metric' => __('ops.health.metrics.'.($this->check['key'] ?? 'disk')),
        ]));
    }

    public function content(): Content
    {
        return new Content(view: 'ops.mail.threshold-alert', with: [
            'check' => $this->check,
            'appName' => (string) config('app.name'),
        ]);
    }
}
