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
 * Admin alert for a stale scheduler heartbeat (spec §0.6 F-0.6-018, WP-13).
 * Properties NOT readonly (D-46).
 *
 * WP-20 (spec §3.15 F-3.15-015): content resolves through
 * SystemEmailRenderer (key `admin-heartbeat-stale`); constructor unchanged.
 */
class CronHeartbeatStaleMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public ?string $lastSeenAt;

    public function __construct(?string $lastSeenAt)
    {
        $this->lastSeenAt = $lastSeenAt;
        $this->onQueue(Queues::MAINTENANCE);
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
        return app(SystemEmailRenderer::class)->resolve('admin-heartbeat-stale', [
            'LAST_SEEN' => $this->lastSeenAt ?? __('sending.heartbeat.never'),
        ]);
    }
}
