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
 * Confirmation email for a public right-to-erasure request (spec §3.25.16
 * F-3.25.16-003 / §4.5 F-4.5-001), carrying the signed confirm link. The URL
 * is generated before queueing so the worker needs no request context.
 *
 * Promoted properties are deliberately NOT readonly (D-46).
 */
class ErasureRequestConfirmMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $confirmUrl,
        public int $expiresInHours = 48,
    ) {
        $this->onQueue(Queues::TRANSACTIONAL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('privacy.erasure.mail.subject'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.privacy.erasure-confirm',
            with: [
                'confirmUrl' => $this->confirmUrl,
                'expiresInHours' => $this->expiresInHours,
            ],
        );
    }
}
