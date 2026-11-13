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
 * The forwarded copy of a campaign (spec §1.6 F-1.6-002).
 *
 * The body is the campaign's WEB VIEW html, rendered before queueing: it
 * carries no recipient-specific tracking pixel, no personal unsubscribe link
 * and no merge-tag data belonging to the forwarder — a friend who was never a
 * subscriber must not inherit anyone's identifiers.
 *
 * D-46: promoted properties on a queued Mailable must not be readonly.
 */
class CampaignForwardMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $subjectLine;

    // Named htmlBody because the base Mailable already declares $html.
    public string $htmlBody;

    public function __construct(string $subjectLine, string $htmlBody)
    {
        $this->subjectLine = $subjectLine;
        $this->htmlBody = $htmlBody;
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
