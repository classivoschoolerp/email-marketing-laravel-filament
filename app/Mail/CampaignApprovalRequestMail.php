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
 * "Campaign awaiting approval" notice to admin users (spec §3.15
 * F-3.15-007, WP-18): queued by CampaignApprovalObserver when a campaign
 * enters pending-approval. Rendered through SystemEmailRenderer (key
 * `campaign-approval-request`, admin-overridable). Properties NOT readonly
 * (D-46).
 */
class CampaignApprovalRequestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $campaignName;

    public string $customerName;

    public string $reviewUrl;

    public function __construct(string $campaignName, string $customerName, string $reviewUrl)
    {
        $this->campaignName = $campaignName;
        $this->customerName = $customerName;
        $this->reviewUrl = $reviewUrl;
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
        return app(SystemEmailRenderer::class)->resolve('campaign-approval-request', [
            'CAMPAIGN_NAME' => $this->campaignName,
            'CUSTOMER_NAME' => $this->customerName,
            'REVIEW_URL' => $this->reviewUrl,
        ]);
    }
}
