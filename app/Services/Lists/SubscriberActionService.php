<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Enums\SubscriberStatus;
use App\Models\EmailList;
use App\Models\Subscriber;

/**
 * Enforces the per-list subscriber actions (spec F-2.2.2-015..018) on status
 * transitions: on subscribe → also subscribe to X / unsubscribe from Y; on
 * unsubscribe → unsubscribe from Z / subscribe to W. Invoked from Subscriber
 * model events so every code path is covered (docs/ARCHITECTURE.md rule 3).
 *
 * Cascades are cycle-safe: each (list, email, direction) pair is processed at
 * most once per top-level transition, so mutually-referencing lists cannot
 * recurse infinitely.
 */
class SubscriberActionService
{
    /** @var array<string, true> */
    private static array $visited = [];

    private static int $depth = 0;

    public function handleSubscribe(Subscriber $subscriber): void
    {
        $this->run($subscriber, 'subscribe');
    }

    public function handleUnsubscribe(Subscriber $subscriber): void
    {
        $this->run($subscriber, 'unsubscribe');
    }

    public function handleStatusChange(Subscriber $subscriber, ?SubscriberStatus $old, SubscriberStatus $new): void
    {
        $wasActive = $old !== null && $old->isActive();

        if (! $wasActive && $new->isActive()) {
            $this->handleSubscribe($subscriber);

            return;
        }

        if ($old !== SubscriberStatus::Unsubscribed && $new === SubscriberStatus::Unsubscribed) {
            $this->handleUnsubscribe($subscriber);
        }
    }

    private function run(Subscriber $subscriber, string $direction): void
    {
        $key = $subscriber->list_id.':'.$subscriber->email.':'.$direction;

        if (isset(self::$visited[$key])) {
            return;
        }

        self::$visited[$key] = true;
        self::$depth++;

        try {
            $list = EmailList::withoutTenancy()->find($subscriber->list_id);

            if ($list === null) {
                return;
            }

            if ($direction === 'subscribe') {
                foreach ((array) $list->on_subscribe_subscribe_ids as $targetId) {
                    $this->subscribeTo((int) $targetId, $subscriber);
                }

                foreach ((array) $list->on_subscribe_unsubscribe_ids as $targetId) {
                    $this->unsubscribeFrom((int) $targetId, $subscriber);
                }
            } else {
                foreach ((array) $list->on_unsubscribe_unsubscribe_ids as $targetId) {
                    $this->unsubscribeFrom((int) $targetId, $subscriber);
                }

                foreach ((array) $list->on_unsubscribe_subscribe_ids as $targetId) {
                    $this->subscribeTo((int) $targetId, $subscriber);
                }
            }
        } finally {
            if (--self::$depth === 0) {
                self::$visited = [];
            }
        }
    }

    private function subscribeTo(int $listId, Subscriber $source): void
    {
        $target = $this->targetList($listId, $source);

        if ($target === null) {
            return;
        }

        $existing = Subscriber::withoutTenancy()
            ->where('customer_id', $source->customer_id)
            ->where('list_id', $target->id)
            ->where('email', $source->email)
            ->first();

        $status = $source->status->isActive() ? $source->status : SubscriberStatus::Confirmed;

        if ($existing !== null) {
            if (! $existing->status->isActive()) {
                $existing->status = $status;
                $existing->save(); // fires the target list's own on-subscribe cascade (cycle-safe)
            }

            return;
        }

        Subscriber::create([
            'customer_id' => $source->customer_id,
            'list_id' => $target->id,
            'email' => $source->email,
            'status' => $status,
            'source' => $source->source,
            'ip' => $source->ip,
            'subscribe_ip' => $source->subscribe_ip,
        ]);
    }

    private function unsubscribeFrom(int $listId, Subscriber $source): void
    {
        $target = $this->targetList($listId, $source);

        if ($target === null) {
            return;
        }

        $existing = Subscriber::withoutTenancy()
            ->where('customer_id', $source->customer_id)
            ->where('list_id', $target->id)
            ->where('email', $source->email)
            ->first();

        if ($existing === null || $existing->status === SubscriberStatus::Unsubscribed) {
            return;
        }

        $existing->status = SubscriberStatus::Unsubscribed;
        $existing->unsubscribed_at ??= now();
        $existing->save(); // fires the target list's own on-unsubscribe cascade (cycle-safe)
    }

    private function targetList(int $listId, Subscriber $source): ?EmailList
    {
        if ($listId === (int) $source->list_id) {
            return null;
        }

        /** @var EmailList|null $list */
        $list = EmailList::withoutTenancy()
            ->where('customer_id', $source->customer_id)
            ->whereKey($listId)
            ->first();

        return $list;
    }
}
