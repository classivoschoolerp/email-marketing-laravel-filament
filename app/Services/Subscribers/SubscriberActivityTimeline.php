<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use App\Models\Campaign;
use App\Models\CampaignBounceLog;
use App\Models\CampaignComplaintLog;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\CampaignTrackUnsubscribe;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-subscriber campaign activity timeline (spec §2.2.5 F-2.2.5-005):
 * deliveries, opens, clicks (with the URL that was hit), bounces, spam
 * complaints and campaign unsubscribes, merged into one reverse-chronological
 * stream.
 *
 * READ-ONLY aggregation. It creates no storage of its own — every row comes
 * from the tracking tables the sending/tracking work packages already write
 * (WP-09/13/15). Nothing here is cached: a profile view is a single-record
 * read of six indexed tables, and a stale timeline would be worse than a
 * slightly more expensive one.
 *
 * Tenancy. The two families of source tables are fenced differently, on
 * purpose:
 *  - campaign_delivery_logs / campaign_bounce_logs / campaign_complaint_logs
 *    are keyed by `subscriber_id`, and the subscriber handed in is already a
 *    tenant-owned record — so the fence is the key itself;
 *  - campaign_track_opens / _clicks / _unsubscribes are keyed by the public
 *    `subscriber_uid` with no customer column, so they are additionally
 *    bounded to this tenant's campaign uids (TrackingScope).
 *
 * Ordering is total and deterministic: newest first by timestamp, then — for
 * events sharing an instant — by how late the event type sits in the message
 * lifecycle (an unsubscribe is reported above the click that produced it),
 * then by descending row id.
 */
final class SubscriberActivityTimeline
{
    public const TYPE_DELIVERY = 'delivery';

    public const TYPE_OPEN = 'open';

    public const TYPE_CLICK = 'click';

    public const TYPE_BOUNCE = 'bounce';

    public const TYPE_COMPLAINT = 'complaint';

    public const TYPE_UNSUBSCRIBE = 'unsubscribe';

    /** Hard ceiling on rows read per source table and returned overall. */
    public const DEFAULT_LIMIT = 200;

    /** Same-instant tie-break: higher = later in the lifecycle = listed first. */
    private const LIFECYCLE_RANK = [
        self::TYPE_DELIVERY => 0,
        self::TYPE_OPEN => 1,
        self::TYPE_CLICK => 2,
        self::TYPE_BOUNCE => 3,
        self::TYPE_COMPLAINT => 4,
        self::TYPE_UNSUBSCRIBE => 5,
    ];

    /**
     * The merged stream, newest first.
     *
     * @return list<array{type: string, at: Carbon|null, campaign_id: int|null, campaign_name: string|null, detail: string|null, id: int}>
     */
    public function events(Subscriber $subscriber, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, $limit);
        $customerId = (int) $subscriber->customer_id;

        $deliveries = CampaignDeliveryLog::query()
            ->where('subscriber_id', $subscriber->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'campaign_id', 'status', 'created_at']);

        $opens = $this->uidQuery(CampaignTrackOpen::query(), $subscriber, $customerId)
            ->limit($limit)
            ->get(['id', 'campaign_uid', 'client', 'created_at']);

        $clicks = $this->uidQuery(CampaignTrackClick::query(), $subscriber, $customerId)
            ->with('url:id,url')
            ->limit($limit)
            ->get(['id', 'campaign_uid', 'url_id', 'created_at']);

        $unsubscribes = $this->uidQuery(CampaignTrackUnsubscribe::query(), $subscriber, $customerId)
            ->limit($limit)
            ->get(['id', 'campaign_uid', 'created_at']);

        $bounces = CampaignBounceLog::query()
            ->where('subscriber_id', $subscriber->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'campaign_id', 'bounce_type', 'message', 'created_at']);

        $complaints = CampaignComplaintLog::query()
            ->where('subscriber_id', $subscriber->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'campaign_id', 'feedback_type', 'created_at']);

        $names = $this->campaignNames(
            $customerId,
            $deliveries->pluck('campaign_id')
                ->merge($bounces->pluck('campaign_id'))
                ->merge($complaints->pluck('campaign_id')),
            $opens->pluck('campaign_uid')
                ->merge($clicks->pluck('campaign_uid'))
                ->merge($unsubscribes->pluck('campaign_uid')),
        );

        $events = [];

        foreach ($deliveries as $row) {
            $events[] = $this->event(
                self::TYPE_DELIVERY,
                $row->created_at,
                (int) $row->id,
                $names['byId'][(int) $row->campaign_id] ?? null,
                (int) $row->campaign_id ?: null,
                __('subscribers.timeline.delivery_status.'.$row->status),
            );
        }

        foreach ($opens as $row) {
            $campaign = $names['byUid'][(string) $row->campaign_uid] ?? null;

            $events[] = $this->event(
                self::TYPE_OPEN,
                $row->created_at,
                (int) $row->id,
                $campaign['name'] ?? null,
                $campaign['id'] ?? null,
                $row->client !== null && $row->client !== '' ? (string) $row->client : null,
            );
        }

        foreach ($clicks as $row) {
            $campaign = $names['byUid'][(string) $row->campaign_uid] ?? null;

            $events[] = $this->event(
                self::TYPE_CLICK,
                $row->created_at,
                (int) $row->id,
                $campaign['name'] ?? null,
                $campaign['id'] ?? null,
                $row->url?->url,
            );
        }

        foreach ($unsubscribes as $row) {
            $campaign = $names['byUid'][(string) $row->campaign_uid] ?? null;

            $events[] = $this->event(
                self::TYPE_UNSUBSCRIBE,
                $row->created_at,
                (int) $row->id,
                $campaign['name'] ?? null,
                $campaign['id'] ?? null,
                null,
            );
        }

        foreach ($bounces as $row) {
            $events[] = $this->event(
                self::TYPE_BOUNCE,
                $row->created_at,
                (int) $row->id,
                $names['byId'][(int) $row->campaign_id] ?? null,
                (int) $row->campaign_id ?: null,
                $row->bounce_type?->label(),
            );
        }

        foreach ($complaints as $row) {
            $events[] = $this->event(
                self::TYPE_COMPLAINT,
                $row->created_at,
                (int) $row->id,
                $names['byId'][(int) $row->campaign_id] ?? null,
                (int) $row->campaign_id ?: null,
                $row->feedback_type !== null && $row->feedback_type !== '' ? (string) $row->feedback_type : null,
            );
        }

        usort($events, function (array $a, array $b): int {
            $at = ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0);

            if ($at !== 0) {
                return $at;
            }

            $rank = self::LIFECYCLE_RANK[$b['type']] <=> self::LIFECYCLE_RANK[$a['type']];

            return $rank !== 0 ? $rank : $b['id'] <=> $a['id'];
        });

        return array_slice($events, 0, $limit);
    }

    /**
     * True counters for the profile header — real totals, not the counts of
     * the (capped) event list above.
     *
     * @return array{delivered: int, opens: int, clicks: int, unsubscribes: int, bounces: int, complaints: int}
     */
    public function summary(Subscriber $subscriber): array
    {
        $customerId = (int) $subscriber->customer_id;

        return [
            'delivered' => CampaignDeliveryLog::query()
                ->where('subscriber_id', $subscriber->id)
                ->where('status', CampaignDeliveryLog::STATUS_SENT)
                ->count(),
            'opens' => $this->uidCount(CampaignTrackOpen::query(), $subscriber, $customerId),
            'clicks' => $this->uidCount(CampaignTrackClick::query(), $subscriber, $customerId),
            'unsubscribes' => $this->uidCount(CampaignTrackUnsubscribe::query(), $subscriber, $customerId),
            'bounces' => CampaignBounceLog::query()->where('subscriber_id', $subscriber->id)->count(),
            'complaints' => CampaignComplaintLog::query()->where('subscriber_id', $subscriber->id)->count(),
        ];
    }

    /** Newest-first, tenant-fenced read of one uid-keyed tracking table. */
    private function uidQuery(Builder $query, Subscriber $subscriber, int $customerId): Builder
    {
        return $query
            ->where('subscriber_uid', $subscriber->uid)
            ->whereIn('campaign_uid', TrackingScope::tenantCampaignUids($customerId))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function uidCount(Builder $query, Subscriber $subscriber, int $customerId): int
    {
        return $query
            ->where('subscriber_uid', $subscriber->uid)
            ->whereIn('campaign_uid', TrackingScope::tenantCampaignUids($customerId))
            ->count();
    }

    /**
     * Resolve campaign labels in one query. Soft-deleted campaigns are
     * included so a deleted campaign still names its own history.
     *
     * @param  Collection<int, mixed>  $ids
     * @param  Collection<int, mixed>  $uids
     * @return array{byId: array<int, string>, byUid: array<string, array{id: int, name: string}>}
     */
    private function campaignNames(int $customerId, Collection $ids, Collection $uids): array
    {
        $ids = $ids->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $uids = $uids->filter()->map(fn (mixed $uid): string => (string) $uid)->unique()->values();

        if ($ids->isEmpty() && $uids->isEmpty()) {
            return ['byId' => [], 'byUid' => []];
        }

        $campaigns = Campaign::withoutTenancy()
            ->withTrashed()
            ->where('customer_id', $customerId)
            ->where(function (Builder $query) use ($ids, $uids): void {
                if ($ids->isNotEmpty()) {
                    $query->orWhereIn('id', $ids->all());
                }

                if ($uids->isNotEmpty()) {
                    $query->orWhereIn('uid', $uids->all());
                }
            })
            ->get(['id', 'uid', 'name']);

        $byId = [];
        $byUid = [];

        foreach ($campaigns as $campaign) {
            $byId[(int) $campaign->id] = (string) $campaign->name;
            $byUid[(string) $campaign->uid] = ['id' => (int) $campaign->id, 'name' => (string) $campaign->name];
        }

        return ['byId' => $byId, 'byUid' => $byUid];
    }

    /**
     * @return array{type: string, at: Carbon|null, campaign_id: int|null, campaign_name: string|null, detail: string|null, id: int}
     */
    private function event(
        string $type,
        ?Carbon $at,
        int $id,
        ?string $campaignName,
        ?int $campaignId,
        ?string $detail,
    ): array {
        return [
            'type' => $type,
            'at' => $at,
            'campaign_id' => $campaignId,
            'campaign_name' => $campaignName,
            'detail' => $detail,
            'id' => $id,
        ];
    }
}
