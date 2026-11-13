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
 * Send-test email for a template (spec §2.5.5 F-2.5.5-004). The HTML is
 * rendered with sample subscriber data BEFORE queueing so the worker needs no
 * tenant context. Queued on the transactional queue (docs/ARCHITECTURE.md rule 5).
 */
class TemplateTestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        // Named htmlBody because the base Mailable already declares $html.
        public readonly string $htmlBody,
    ) {
        $this->onQueue(Queues::TRANSACTIONAL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }
}
