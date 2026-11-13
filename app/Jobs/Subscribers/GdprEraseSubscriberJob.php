<?php

declare(strict_types=1);

namespace App\Jobs\Subscribers;

use App\Enums\SubscriberStatus;
use App\Jobs\Concerns\IdempotentJob;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * GDPR right-to-erasure (spec F-2.2.5-010): anonymises the subscriber row in
 * place — email replaced with an undeliverable placeholder, IPs, reason and
 * internal notes wiped, all custom field values deleted — while keeping the row so campaign
 * statistics stay consistent. The write is quiet (no model events): erasure
 * is a legal operation, not a subscribe/unsubscribe transition, so the
 * list's subscriber-actions cascade must not fire.
 */
class GdprEraseSubscriberJob implements ShouldQueue
{
    use Dispatchable;
    use IdempotentJob;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly int $customerId,
        public readonly int $subscriberId,
        public readonly string $operationId,
    ) {
        $this->onQueue(Queues::MAINTENANCE);
    }

    public function handle(): void
    {
        /** @var Subscriber|null $subscriber */
        $subscriber = Subscriber::withoutTenancy()
            ->where('customer_id', $this->customerId)
            ->find($this->subscriberId);

        if ($subscriber === null) {
            return;
        }

        SubscriberFieldValue::withoutTenancy()
            ->where('subscriber_id', $subscriber->id)
            ->delete();

        $subscriber->forceFill([
            'email' => 'erased-'.$subscriber->uid.'@gdpr.invalid',
            'ip' => null,
            'subscribe_ip' => null,
            'confirm_ip' => null,
            'unsubscribe_ip' => null,
            'unsubscribe_reason' => null,
            // Internal notes (F-2.2.5-011) are prose an operator wrote ABOUT
            // this person — personal data, so erasure must take them too.
            'notes' => null,
            'status' => SubscriberStatus::Unsubscribed,
            'unsubscribed_at' => $subscriber->unsubscribed_at ?? now(),
        ])->saveQuietly();
    }

    public function idempotencyKey(): string
    {
        return 'subscriber-gdpr-erase:'.$this->subscriberId.':'.$this->operationId;
    }
}
