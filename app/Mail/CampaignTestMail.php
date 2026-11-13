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
 * Send-test email for a campaign (spec §2.3.5 F-2.3.5-003). Merge tags and
 * spintax are rendered with sample data BEFORE queueing so the worker needs
 * no tenant context. Queued on the transactional queue (docs/ARCHITECTURE.md rule 5).
 * Properties are deliberately NOT readonly (D-46: queued-Mailable
 * unserialization initializes them from subclass scope — fatal on readonly).
 */
class CampaignTestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $subjectLine;

    // Named htmlBody because the base Mailable already declares $html.
    public string $htmlBody;

    public ?string $textBody;

    public function __construct(string $subjectLine, string $htmlBody, ?string $textBody = null)
    {
        $this->subjectLine = $subjectLine;
        $this->htmlBody = $htmlBody;
        $this->textBody = $textBody;
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
