<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\ListPageType;
use App\Mail\Lists\SubscribeConfirmMail;
use App\Mail\Lists\WelcomeMail;
use App\Models\EmailList;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Mail;

/**
 * Composes and queues the §1.5 list lifecycle emails. Content comes from the
 * list page types (3-layer merge via ListPageResolver), merge tags are
 * substituted and the CAN-SPAM footer appended BEFORE queueing so the worker
 * needs no tenant/request context.
 */
class ListMailer
{
    public function __construct(
        private readonly PublicPageRenderer $renderer,
        private readonly ListPageResolver $resolver,
    ) {}

    /** Queue the double opt-in confirmation email (F-1.5-004). */
    public function sendSubscribeConfirmation(EmailList $list, Subscriber $subscriber): void
    {
        Mail::to($subscriber->email)->queue(new SubscribeConfirmMail(
            subjectLine: (string) $this->renderer->subject($list, ListPageType::SubscribeConfirmEmail, $subscriber),
            htmlContent: $this->renderer->html($list, ListPageType::SubscribeConfirmEmail, $subscriber)
                .$this->renderer->footer($list),
            fromEmail: $list->from_email,
            fromName: $list->from_name,
            replyToEmail: $list->reply_to,
        ));
    }

    /**
     * Queue the welcome email (F-1.5-005) when the welcome-email page
     * content is enabled — i.e. resolves non-empty. With the shipped
     * defaults it always is; clearing the content at every layer disables it.
     */
    public function sendWelcome(EmailList $list, Subscriber $subscriber): void
    {
        if (trim($this->resolver->content($list, ListPageType::WelcomeEmail)) === '') {
            return;
        }

        Mail::to($subscriber->email)->queue(new WelcomeMail(
            subjectLine: (string) $this->renderer->subject($list, ListPageType::WelcomeEmail, $subscriber),
            htmlContent: $this->renderer->html($list, ListPageType::WelcomeEmail, $subscriber)
                .$this->renderer->footer($list),
            fromEmail: $list->from_email,
            fromName: $list->from_name,
            replyToEmail: $list->reply_to,
        ));
    }
}
