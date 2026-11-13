<?php

declare(strict_types=1);

namespace App\Services\Lists;

use App\Models\EmailList;
use App\Models\Subscriber;
use Illuminate\Support\Str;

/**
 * Clones a subscriber row (+ custom field values, matched across lists by
 * merge tag) into another list withoutEvents — an administrative copy is not
 * a subscribe event, so list cascades must NOT fire (same convention as the
 * WP-06/07 bulk copy). Used by the §2.2.13 split and sync tools.
 */
class SubscriberCloner
{
    /** Returns null when the email already exists in the target list. */
    public function cloneTo(Subscriber $subscriber, EmailList $target): ?Subscriber
    {
        $exists = Subscriber::withoutTenancy()
            ->where('list_id', $target->id)
            ->where('email', $subscriber->email)
            ->exists();

        if ($exists) {
            return null;
        }

        $sourceTags = $subscriber->list?->fields()->pluck('tag', 'id') ?? collect();
        $targetIds = $target->fields()->pluck('id', 'tag');

        return Subscriber::withoutEvents(function () use ($subscriber, $target, $sourceTags, $targetIds): Subscriber {
            /** @var Subscriber $clone */
            $clone = $subscriber->replicate(['uid']);
            $clone->uid = (string) Str::uuid();
            $clone->list_id = $target->id;
            $clone->save();

            foreach ($subscriber->fieldValues()->get() as $value) {
                $tag = $sourceTags->get($value->list_field_id);
                $targetFieldId = $tag === null ? null : $targetIds->get($tag);

                if ($targetFieldId === null) {
                    continue;
                }

                $newValue = $value->replicate();
                $newValue->subscriber_id = $clone->id;
                $newValue->list_field_id = (int) $targetFieldId;
                $newValue->save();
            }

            return $clone;
        });
    }
}
