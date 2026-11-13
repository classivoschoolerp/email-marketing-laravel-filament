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
 * Confirmation email for a public "never email me again" request (spec
 * §1.10 F-1.10-002) carrying the signed confirm link. The URL is generated
 * before queueing so the worker needs no request context.
 *
 * WP-20 (spec §3.15 F-3.15-014): subject + body resolve through
 * SystemEmailRenderer (key `block-request-confirmation`, admin-overridable)
 * instead of the markdown view; the public constructor API is unchanged.
 */
class BlockRequestConfirmMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $confirmUrl)
    {
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
        return app(SystemEmailRenderer::class)->resolve('block-request-confirmation', [
            'CONFIRM_URL' => $this->confirmUrl,
        ]);
    }
}
