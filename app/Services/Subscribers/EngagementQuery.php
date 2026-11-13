<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * The single place that turns "opened / clicked / did not open / did not
 * click" into SQL over the WP-09 tracking tables (spec §1.7 / §2.2.4
 * F-2.2.4-003, §2.2.10 F-2.2.10-003).
 *
 * campaign_track_opens / campaign_track_clicks are keyed by the public uids
 * (campaign_uid + subscriber_uid), never by row ids, so every predicate is a
 * correlated EXISTS on `subscribers.uid`. That keeps the subscriber query
 * itself un-joined — no row multiplication, no DISTINCT — which matters
 * because both callers then count or paginate the result.
 *
 * "Any campaign" is ALWAYS fenced to the tenant's own campaigns: uids are
 * globally unique in practice, but the fence makes cross-tenant leakage
 * structurally impossible rather than merely unlikely.
 */
class EngagementQuery
{
    public const TABLE_OPENS = 'campaign_track_opens';

    public const TABLE_CLICKS = 'campaign_track_clicks';

    /** Engagement modes offered by the subscribers index filter. */
    public const MODE_OPENED = 'opened';

    public const MODE_NOT_OPENED = 'not_opened';

    public const MODE_CLICKED = 'clicked';

    public const MODE_NOT_CLICKED = 'not_clicked';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_OPENED, self::MODE_NOT_OPENED, self::MODE_CLICKED, self::MODE_NOT_CLICKED];
    }

    /** @return array<string, string> value => label */
    public static function modeOptions(): array
    {
        $options = [];

        foreach (self::modes() as $mode) {
            $options[$mode] = __('subscribers.engagement.modes.'.$mode);
        }

        return $options;
    }

    /**
     * Subscribers-index filter (F-2.2.4-003): engagement across ANY campaign
     * of this tenant, optionally within the last N days.
     */
    public function applyMode(Builder $query, string $mode, int $customerId, int $withinDays = 0): void
    {
        $since = $withinDays > 0 ? Carbon::now()->subDays($withinDays) : null;

        match ($mode) {
            self::MODE_OPENED => $this->whereEvent($query, self::TABLE_OPENS, $customerId, null, $since, null, false),
            self::MODE_NOT_OPENED => $this->whereEvent($query, self::TABLE_OPENS, $customerId, null, $since, null, true),
            self::MODE_CLICKED => $this->whereEvent($query, self::TABLE_CLICKS, $customerId, null, $since, null, false),
            self::MODE_NOT_CLICKED => $this->whereEvent($query, self::TABLE_CLICKS, $customerId, null, $since, null, true),
            default => null, // unknown mode = no constraint, the filter is off
        };
    }

    /**
     * EXISTS / NOT EXISTS against one tracking table, correlated on the
     * subscriber uid.
     *
     * @param  string  $table  self::TABLE_OPENS or self::TABLE_CLICKS
     * @param  string|null  $campaignUid  null = any campaign owned by $customerId
     * @param  Carbon|null  $since  null = no time window
     * @param  int|null  $linkId  campaign_track_urls.id, clicks only
     */
    public function whereEvent(
        Builder $query,
        string $table,
        int $customerId,
        ?string $campaignUid,
        ?Carbon $since,
        ?int $linkId,
        bool $negate,
    ): void {
        $callback = function (QueryBuilder $sub) use ($table, $customerId, $campaignUid, $since, $linkId): void {
            $sub->selectRaw('1')
                ->from($table)
                ->whereColumn($table.'.subscriber_uid', 'subscribers.uid');

            if ($campaignUid !== null) {
                $sub->where($table.'.campaign_uid', $campaignUid);
            } else {
                $sub->whereIn($table.'.campaign_uid', function (QueryBuilder $campaigns) use ($customerId): void {
                    $campaigns->select('uid')
                        ->from('campaigns')
                        ->where('customer_id', $customerId)
                        ->whereNull('deleted_at');
                });
            }

            if ($since !== null) {
                $sub->where($table.'.created_at', '>=', $since);
            }

            if ($linkId !== null) {
                $sub->where($table.'.url_id', $linkId);
            }
        };

        $negate ? $query->whereNotExists($callback) : $query->whereExists($callback);
    }
}
