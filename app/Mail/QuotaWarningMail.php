<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\SystemEmails\QuotaNotifier;
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
 * Sending-quota warning threshold mail (spec §3.15 F-3.15-008, WP-20):
 * queued by {@see QuotaNotifier} when a
 * customer's monthly usage crosses the warning percentage. Properties NOT
 * readonly (D-46). Rendered through SystemEmailRenderer (key
 * `quota-warning`, admin-overridable).
 */
class QuotaWarningMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $used;

    public int $limit;

    public int $percent;

    public function __construct(int $used, int $limit, int $percent)
    {
        $this->used = $used;
        $this->limit = $limit;
        $this->percent = $percent;
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
        return app(SystemEmailRenderer::class)->resolve('quota-warning', [
            'USED' => number_format($this->used),
            'LIMIT' => number_format($this->limit),
            'PERCENT' => $this->percent,
        ]);
    }
}
