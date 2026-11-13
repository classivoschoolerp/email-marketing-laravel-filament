<?php

declare(strict_types=1);

namespace App\Jobs\Subscribers;

use App\Jobs\Concerns\IdempotentJob;
use App\Mail\Lists\SingleSubscriberMail;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Content\MergeTags;
use App\Services\Lists\PublicPageRenderer;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * "Send single email" from the subscriber profile (spec §2.2.5 F-2.2.5-010).
 *
 * Queued on the transactional queue per docs/ARCHITECTURE.md rule 5, and rendered with
 * the SAME canonical merge-tag engine the campaign send path uses
 * (App\Services\Content\MergeTags) so `{FNAME|there}`, `{UNSUBSCRIBE_URL}`
 * and every list/company tag resolve exactly as they would in a campaign.
 * The list's CAN-SPAM footer is appended, and the from/reply-to identity
 * comes from the list — a one-off must not be less compliant than a campaign.
 *
 * Tenant-fenced: the subscriber is looked up by (customer_id, id), so a
 * forged id from another tenant resolves to null and nothing is sent.
 */
class SendSingleEmailJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $subscriberId,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::TRANSACTIONAL);
    }

    public function handle(MergeTags $mergeTags, PublicPageRenderer $renderer): void
    {
        /** @var Subscriber|null $subscriber */
        $subscriber = Subscriber::withoutTenancy()
            ->where('customer_id', $this->customerId)
            ->find($this->subscriberId);

        if ($subscriber === null || $subscriber->trashed()) {
            return;
        }

        /** @var EmailList|null $list */
        $list = EmailList::withoutTenancy()
            ->where('customer_id', $this->customerId)
            ->find($subscriber->list_id);

        if ($list === null) {
            return;
        }

        $seed = 'single:'.$subscriber->uid.':'.$this->operationId;

        $subject = $mergeTags->renderFor($this->subject, null, $list, $subscriber, $seed);
        $body = $mergeTags->renderFor($this->html, null, $list, $subscriber, $seed);

        Mail::to($subscriber->email)->queue(new SingleSubscriberMail(
            subjectLine: $subject,
            htmlContent: $body.$renderer->footer($list),
            fromEmail: $list->from_email,
            fromName: $list->from_name,
            replyToEmail: $list->reply_to,
        ));
    }

    public function idempotencyKey(): string
    {
        return 'subscriber-single-email:'.$this->operationId;
    }
}
