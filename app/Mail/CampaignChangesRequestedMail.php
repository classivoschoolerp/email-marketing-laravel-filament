<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\SystemEmails\RenderedSystemEmail;
use App\Services\SystemEmails\SystemEmailRenderer;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Changes requested" notice to the owning customer (spec §3.7 F-3.7-004,
 * WP-42): queued when an admin sends a campaign back from the approval queue
 * to draft instead of approving or blocking it.
 *
 * Distinct from CampaignBlockedMail on purpose — blocked is terminal and
 * accusatory, this one says "edit it and resubmit", which is what the
 * pending-approval → draft transition actually means (D-57). Rendered through
 * SystemEmailRenderer (key `campaign-changes-requested`, admin-overridable).
 * Properties NOT readonly (D-46).
 */
class CampaignChangesRequestedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $campaignName;

    public ?string $reason;

    public function __construct(string $campaignName, ?string $reason = null)
    {
        $this->campaignName = $campaignName;
        $this->reason = $reason;
        $this->onQueue(Queues::TRANSACTIONAL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered()->subject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->rendered()->html);
    }

    private function rendered(): RenderedSystemEmail
    {
        return app(SystemEmailRenderer::class)->resolve('campaign-changes-requested', [
            'CAMPAIGN_NAME' => $this->campaignName,
            'REASON' => $this->reason ?? '',
        ]);
    }
}
