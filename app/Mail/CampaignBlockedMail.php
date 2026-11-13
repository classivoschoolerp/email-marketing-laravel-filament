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
 * "Campaign blocked" notice to the owning customer (spec §3.15 F-3.15-007,
 * WP-18): queued by the admin block action (§3.7) with the optional reason.
 * Rendered through SystemEmailRenderer (key `campaign-blocked`,
 * admin-overridable). Properties NOT readonly (D-46).
 */
class CampaignBlockedMail extends Mailable implements ShouldQueue
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
        return app(SystemEmailRenderer::class)->resolve('campaign-blocked', [
            'CAMPAIGN_NAME' => $this->campaignName,
            'REASON' => $this->reason ?? '',
        ]);
    }
}
