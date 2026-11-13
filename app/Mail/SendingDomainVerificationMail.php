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
 * Sending-domain DNS verification result mail (spec §3.15 F-3.15-010,
 * WP-20). The mailable + renderer key are ready; dispatching from the WP-15
 * domain verification command is a one-line follow-up for the coordinator
 * (WP-15 files are frozen for WP-20). Properties NOT readonly (D-46).
 * Rendered through SystemEmailRenderer (key `sending-domain-verification`,
 * admin-overridable).
 */
class SendingDomainVerificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $domainName;

    public bool $verified;

    public ?string $details;

    public function __construct(string $domainName, bool $verified, ?string $details = null)
    {
        $this->domainName = $domainName;
        $this->verified = $verified;
        $this->details = $details;
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
        return app(SystemEmailRenderer::class)->resolve('sending-domain-verification', [
            'DOMAIN_NAME' => $this->domainName,
            'RESULT' => $this->verified
                ? __('system-emails.results.verified')
                : __('system-emails.results.failed'),
            'DETAILS' => $this->details ?? '',
        ]);
    }
}
