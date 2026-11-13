<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use App\Enums\SubscriberStatus;
use App\Models\Subscriber;
use Illuminate\Support\Carbon;

/**
 * Cross-list view (spec §2.2.5 F-2.2.5-012): every list of the SAME customer
 * that holds a subscriber row with this email address, with the per-list
 * status and subscribe date.
 *
 * The fence is explicit — `customer_id` is read off the record being viewed
 * and pinned in the WHERE clause rather than relying on the ambient
 * CustomerScope (D-05), because this query keys on an email address, and an
 * email address is the one identifier that genuinely recurs across tenants.
 * Without the pin, "which lists is bob@example.com on?" would answer for the
 * whole platform. Soft-deleted subscriber rows are excluded by the model's
 * SoftDeletes scope, and rows on a soft-deleted list are filtered out too — a
 * list in the trash is not a membership.
 */
final class SubscriberListMemberships
{
    /**
     * @return list<array{
     *     subscriber_id: int,
     *     list_id: int,
     *     list_name: string,
     *     status: SubscriberStatus,
     *     subscribed_at: Carbon|null,
     *     current: bool,
     * }>
     */
    public function for(Subscriber $subscriber): array
    {
        $email = mb_strtolower(trim((string) $subscriber->email));

        if ($email === '') {
            return [];
        }

        $rows = Subscriber::withoutTenancy()
            ->where('subscribers.customer_id', (int) $subscriber->customer_id)
            ->where('subscribers.email', $email)
            ->join('lists', 'lists.id', '=', 'subscribers.list_id')
            ->whereNull('lists.deleted_at')
            ->orderBy('lists.name')
            ->orderBy('subscribers.id')
            ->get([
                'subscribers.id as subscriber_id',
                'subscribers.list_id as list_id',
                'subscribers.status as status',
                'subscribers.subscribed_at as subscribed_at',
                'lists.name as list_name',
            ]);

        $memberships = [];

        foreach ($rows as $row) {
            $status = $row->getAttribute('status');
            $subscribedAt = $row->getAttribute('subscribed_at');

            $memberships[] = [
                'subscriber_id' => (int) $row->getAttribute('subscriber_id'),
                'list_id' => (int) $row->getAttribute('list_id'),
                'list_name' => (string) $row->getAttribute('list_name'),
                'status' => $status instanceof SubscriberStatus ? $status : SubscriberStatus::from((string) $status),
                'subscribed_at' => $subscribedAt instanceof Carbon ? $subscribedAt : ($subscribedAt === null ? null : Carbon::parse((string) $subscribedAt)),
                'current' => (int) $row->getAttribute('subscriber_id') === (int) $subscriber->id,
            ];
        }

        return $memberships;
    }
}
