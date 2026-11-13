<?php

declare(strict_types=1);

namespace App\Mail\Lists;

use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Base for the §1.5 list lifecycle emails (double opt-in confirm + welcome).
 * Subject and HTML are fully rendered (merge tags substituted, CAN-SPAM
 * footer appended) BEFORE queueing — the mail worker needs no tenant
 * context, exactly like ExportReadyMail. Sender falls back to the app
 * default when the list has no from address.
 */
abstract class ListPageEmail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    // Named htmlContent because the base Mailable already declares $html.
    // NOT readonly: queued-mailable unserialization initializes these from the
    // subclass scope, which PHP 8.3 forbids for parent-declared readonly props.
    public function __construct(
        public string $subjectLine,
        public string $htmlContent,
        public ?string $fromEmail = null,
        public ?string $fromName = null,
        public ?string $replyToEmail = null,
    ) {
        $this->onQueue(Queues::TRANSACTIONAL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
            from: $this->fromEmail !== null && trim($this->fromEmail) !== ''
                ? new Address($this->fromEmail, $this->fromName ?? '')
                : null,
            replyTo: $this->replyToEmail !== null && trim($this->replyToEmail) !== ''
                ? [new Address($this->replyToEmail)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlContent);
    }
}
