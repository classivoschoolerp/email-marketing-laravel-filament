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
 * "Your export is ready" notification (spec F-2.2.8-004) with the signed,
 * expiring download link. The URL is generated before queueing so the mail
 * worker needs no tenant context.
 *
 * WP-20 (spec §3.15 F-3.15-011): subject + body resolve through
 * SystemEmailRenderer (key `export-ready`, admin-overridable) instead of the
 * markdown view; the public constructor API is unchanged.
 */
class ExportReadyMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $exportName,
        public readonly string $downloadUrl,
        public readonly ?string $expiresAtFormatted,
    ) {
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
        return app(SystemEmailRenderer::class)->resolve('export-ready', [
            'EXPORT_NAME' => $this->exportName,
            'DOWNLOAD_URL' => $this->downloadUrl,
            'EXPIRES_LINE' => $this->expiresAtFormatted !== null
                ? __('imports.export.mail.expires', ['date' => $this->expiresAtFormatted])
                : '',
        ]);
    }
}
