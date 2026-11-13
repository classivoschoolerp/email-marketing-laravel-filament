<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * F-2.2.2-009 / §2.15.3 "new subscriber" notice (WP-39).
 *
 * The `lists.subscribe_notify` + `subscribe_notify_emails` columns have
 * existed since WP-06/07 and the list editor has always written them —
 * nothing read them until now.
 *
 * D-46: promoted constructor properties on a queued Mailable must NOT be
 * readonly.
 */
class NewSubscriberMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $listName,
        public string $subscriberEmail,
    ) {
        $this->onQueue(Queues::TRANSACTIONAL);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('lists.notifications.new_subscriber.subject', [
            'list' => $this->listName,
        ]));
    }

    public function content(): Content
    {
        $html = '<div style="padding:16px;font-family:Arial,sans-serif;font-size:14px;color:#111;">'
            .'<p style="margin:0;">'
            .e(__('lists.notifications.new_subscriber.line', [
                'email' => $this->subscriberEmail,
                'list' => $this->listName,
            ]))
            .'</p></div>';

        return new Content(htmlString: $html);
    }
}
