<?php

declare(strict_types=1);

namespace App\Mail\Customers;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ad-hoc admin → customer email (spec §3.4 F-3.4-011). Queued — never sent
 * inline (docs/ARCHITECTURE.md queues rule).
 */
class AdminCustomerMessage extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $messageSubject,
        public readonly string $messageBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageSubject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.customers.admin-message',
            with: ['body' => $this->messageBody],
        );
    }
}
