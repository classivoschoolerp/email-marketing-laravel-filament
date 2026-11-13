<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Notify admin" monitor action (spec §2.9.6 F-2.9.6-003, WP-33). Properties
 * NOT readonly (D-46). Content is a plain localized body rather than a
 * §3.15 system-email template: the payload is operational (which monitor,
 * which message) and has no customer-facing branding.
 */
class MonitorAlertMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $monitorName;

    public string $messageSubject;

    public string $fromEmail;

    public function __construct(string $monitorName, string $messageSubject, string $fromEmail)
    {
        $this->monitorName = $monitorName;
        $this->messageSubject = $messageSubject;
        $this->fromEmail = $fromEmail;
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('infra.monitors.alert.subject', ['monitor' => $this->monitorName]));
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.e(__('infra.monitors.alert.body', [
            'monitor' => $this->monitorName,
            'from' => $this->fromEmail,
            'subject' => $this->messageSubject,
        ])).'</p>');
    }
}
