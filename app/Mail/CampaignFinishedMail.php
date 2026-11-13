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
 * notify_on_finish mail (spec §2.3.4 F-2.3.4-021, WP-13): queued to the
 * campaign owner when the last batch completes. Content is pre-rendered so
 * the worker needs no tenant context. Properties NOT readonly (D-46).
 *
 * WP-20 (spec §3.15 F-3.15-006): subject + body resolve through
 * SystemEmailRenderer (key `campaign-finished`) so the admin override wins;
 * the public constructor API is unchanged.
 */
class CampaignFinishedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $campaignName;

    public int $sent;

    public int $failed;

    public int $total;

    public function __construct(string $campaignName, int $sent, int $failed, int $total)
    {
        $this->campaignName = $campaignName;
        $this->sent = $sent;
        $this->failed = $failed;
        $this->total = $total;
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
        return app(SystemEmailRenderer::class)->resolve('campaign-finished', [
            'CAMPAIGN_NAME' => $this->campaignName,
            'SENT_COUNT' => number_format($this->sent),
            'FAILED_COUNT' => number_format($this->failed),
            'TOTAL_COUNT' => number_format($this->total),
        ]);
    }
}
