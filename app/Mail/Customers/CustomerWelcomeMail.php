<?php

declare(strict_types=1);

namespace App\Mail\Customers;

use App\Services\Auth\RegistrationService;
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
 * Customer welcome email (spec §3.15 F-3.15-002, WP-20): queued when the
 * account becomes Active after email confirmation
 * ({@see RegistrationService::confirm()}). Scalar
 * constructor so the worker needs no tenant context; properties NOT
 * readonly (D-46). Rendered through SystemEmailRenderer (key
 * `customer-welcome`, admin-overridable).
 */
class CustomerWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $firstName;

    public string $lastName;

    public function __construct(string $firstName, string $lastName)
    {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
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
        return app(SystemEmailRenderer::class)->resolve('customer-welcome', [
            'FIRST_NAME' => $this->firstName,
            'LAST_NAME' => $this->lastName,
            'LOGIN_URL' => route('filament.customer.auth.login'),
        ]);
    }
}
