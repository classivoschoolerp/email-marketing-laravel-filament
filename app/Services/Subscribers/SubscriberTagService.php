<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use App\Models\Subscriber;
use App\Models\SubscriberTag;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Minimal tag attach/detach API (WP-21, spec §0.5 subscriber_tags). Going
 * through this service (or the SubscriberTag model directly) fires the
 * created/deleted model events the automation hooks listen to
 * (F-2.4.2-007 tag triggers). Names are normalized lowercase; attach is
 * idempotent.
 */
class SubscriberTagService
{
    public function attach(Subscriber $subscriber, string $name): ?SubscriberTag
    {
        $name = SubscriberTag::normalize($name);

        if ($name === '') {
            return null;
        }

        if ($this->has($subscriber, $name)) {
            return null;
        }

        try {
            return SubscriberTag::withoutTenancy()->create([
                'customer_id' => $subscriber->customer_id,
                'subscriber_id' => $subscriber->id,
                'name' => $name,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null; // concurrent attach — already present
        }
    }

    public function detach(Subscriber $subscriber, string $name): bool
    {
        $name = SubscriberTag::normalize($name);

        $tag = SubscriberTag::withoutTenancy()
            ->where('subscriber_id', $subscriber->id)
            ->where('name', $name)
            ->first();

        // delete() (not a bulk query) so the deleted model event fires.
        return $tag !== null && (bool) $tag->delete();
    }

    public function has(Subscriber $subscriber, string $name): bool
    {
        return SubscriberTag::withoutTenancy()
            ->where('subscriber_id', $subscriber->id)
            ->where('name', SubscriberTag::normalize($name))
            ->exists();
    }

    /** @return list<string> */
    public function tagsFor(Subscriber $subscriber): array
    {
        return SubscriberTag::withoutTenancy()
            ->where('subscriber_id', $subscriber->id)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
