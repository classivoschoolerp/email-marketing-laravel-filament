<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Enums\SendFrequency;
use App\Enums\SubscriberStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignFilter;
use App\Models\CampaignTrackUrl;
use App\Models\Subscriber;
use App\Services\Privacy\ConsentRules;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Live recipient estimation (spec §2.3.5 F-2.3.5-002): confirmed subscribers
 * of the target list, narrowed by the optional segment through
 * SegmentQueryBuilder (dynamic membership, computed at read time).
 *
 * WP-16 (§2.9.11 F-2.9.11-004): the campaign's attached suppression lists
 * (campaigns.suppression_list_ids) are subtracted HERE — the single choke
 * point every consumer (send-wave snapshot, scheduler cap, wizard estimate,
 * index column) reads, so a suppressed address is neither counted nor sent.
 *
 * WP-35 adds three more narrowings to the SAME choke point, so the wizard's
 * count preview, the send wave and the API can never disagree:
 *   §2.3.2 F-2.3.2-006 — extra target lists, de-duplicated by email;
 *   §2.3.7 F-2.3.7-001..004 — opened/clicked engagement filters with
 *     include/exclude combination;
 *   §2.3.4 F-2.3.4-014 — the frequency cap (skip recently-emailed people).
 */
class CampaignRecipients
{
    public function __construct(private readonly SegmentQueryBuilder $segments) {}

    /** Query of sendable subscribers for the campaign's current targeting. */
    public function query(Campaign $campaign): ?Builder
    {
        if ($campaign->email_list_id === null) {
            return null;
        }

        $segment = $campaign->list_segment_id !== null ? $campaign->segment : null;

        $primary = $segment !== null && $segment->list_id === $campaign->email_list_id
            ? $this->segments->subscribers($segment)
            : Subscriber::query()->where('list_id', $campaign->email_list_id);

        $query = $this->widenToExtraLists($campaign, $primary);

        $query = $this->subtractSuppressionLists($campaign, $query)
            ->where('subscribers.status', SubscriberStatus::Confirmed);

        $query = $this->applyEngagementFilters($campaign, $query);

        $query = $this->applyFrequencyCap($campaign, $query);

        $query = $this->applySubscriberFrequency($campaign, $query);

        return $this->applyConsentRules($campaign, $query);
    }

    public function count(Campaign $campaign): int
    {
        return $this->query($campaign)?->count() ?? 0;
    }

    /* ================================================================ *
     *  §2.3.2 F-2.3.2-006 — multiple target lists
     * ================================================================ */

    /**
     * OR the extra target lists into the primary (segmented) predicate and
     * de-duplicate by email, so someone subscribed to two targeted lists is
     * mailed once. The primary list's segment deliberately does NOT apply to
     * the extra lists: a segment belongs to one list by definition (§2.2.10),
     * and silently re-interpreting its field conditions against another list's
     * fields would change who gets mailed without saying so.
     *
     * @param  Builder<Subscriber>  $primary
     * @return Builder<Subscriber>
     */
    private function widenToExtraLists(Campaign $campaign, Builder $primary): Builder
    {
        $listIds = $campaign->targetListIds();
        $extra = array_values(array_filter(
            $listIds,
            fn (int $id): bool => $id !== (int) $campaign->email_list_id,
        ));

        if ($extra === []) {
            return $primary;
        }

        /** @var Builder<Subscriber> $query */
        $query = Subscriber::query();

        $query->where(function (Builder $outer) use ($primary, $extra): void {
            // addNestedWhereQuery folds the already-built primary predicate
            // (segment conditions and all, with its bindings) in as one group.
            $outer->getQuery()->addNestedWhereQuery($primary->getQuery(), 'and');
            $outer->orWhereIn('subscribers.list_id', $extra);
        });

        // Keep the lowest subscriber id per address across the targeted lists.
        return $query->whereNotExists(function (QueryBuilder $sub) use ($listIds): void {
            $sub->select(DB::raw(1))
                ->from('subscribers as dupe')
                ->whereColumn('dupe.email', 'subscribers.email')
                ->whereColumn('dupe.id', '<', 'subscribers.id')
                ->whereIn('dupe.list_id', $listIds)
                ->where('dupe.status', SubscriberStatus::Confirmed->value)
                ->whereNull('dupe.deleted_at');
        });
    }

    /* ================================================================ *
     *  §2.3.7 — engagement filters
     * ================================================================ */

    /**
     * Include filters are combined with the campaign's `filter_match`
     * (all ⇒ AND, any ⇒ OR); every exclude filter is then subtracted, which is
     * AND NOT of each — logically identical to NOT(any exclude matched).
     *
     * @param  Builder<Subscriber>  $query
     * @return Builder<Subscriber>
     */
    private function applyEngagementFilters(Campaign $campaign, Builder $query): Builder
    {
        $filters = $campaign->filters;

        if ($filters->isEmpty()) {
            return $query;
        }

        $includes = $filters->where('mode', CampaignFilter::MODE_INCLUDE)->values();
        $excludes = $filters->where('mode', CampaignFilter::MODE_EXCLUDE)->values();
        $any = (string) $campaign->filter_match === CampaignFilter::MATCH_ANY;

        if ($includes->isNotEmpty()) {
            $query->where(function (Builder $group) use ($includes, $any, $campaign): void {
                foreach ($includes as $index => $filter) {
                    $group->where(
                        fn (Builder $inner) => $this->applyFilter($inner, $campaign, $filter),
                        null,
                        null,
                        $any && $index > 0 ? 'or' : 'and',
                    );
                }
            });
        }

        foreach ($excludes as $filter) {
            $query->whereNot(fn (Builder $inner) => $this->applyFilter($inner, $campaign, $filter));
        }

        return $query;
    }

    /**
     * One engagement predicate.
     *
     * D-90 semantics are preserved exactly: an unknown, deleted or
     * cross-tenant target campaign (or a link that is not one of its tracked
     * URLs) matches NOTHING in both directions — including "did not open".
     * Widening the audience on a broken filter would mail people the customer
     * never targeted.
     *
     * @param  Builder<Subscriber>  $query
     */
    private function applyFilter(Builder $query, Campaign $campaign, CampaignFilter $filter): void
    {
        $target = $this->targetCampaign($campaign, $filter);

        if ($target === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $clicks = $filter->measuresClicks();
        $table = $clicks ? 'campaign_track_clicks' : 'campaign_track_opens';
        $urlId = $clicks ? $this->targetUrlId($target, $filter) : null;

        if ($clicks && $filter->campaign_track_url_id !== null && $urlId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $matcher = function (QueryBuilder $sub) use ($table, $target, $urlId): void {
            $sub->select(DB::raw(1))
                ->from($table)
                ->where($table.'.campaign_uid', $target->uid)
                ->whereColumn($table.'.subscriber_uid', 'subscribers.uid');

            if ($urlId !== null) {
                $sub->where($table.'.url_id', $urlId);
            }
        };

        $filter->isNegated()
            ? $query->whereNotExists($matcher)
            : $query->whereExists($matcher);
    }

    /** The filter's target campaign, re-fenced to the campaign's own tenant. */
    private function targetCampaign(Campaign $campaign, CampaignFilter $filter): ?Campaign
    {
        if ($filter->target_campaign_id === null || $campaign->customer_id === null) {
            return null;
        }

        return Campaign::withoutTenancy()
            ->whereKey((int) $filter->target_campaign_id)
            ->where('customer_id', $campaign->customer_id)
            ->first(['id', 'uid', 'customer_id']);
    }

    /** The tracked URL id, but only when it belongs to the target campaign. */
    private function targetUrlId(Campaign $target, CampaignFilter $filter): ?int
    {
        if ($filter->campaign_track_url_id === null) {
            return null;
        }

        $id = CampaignTrackUrl::query()
            ->whereKey((int) $filter->campaign_track_url_id)
            ->where('campaign_uid', $target->uid)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /* ================================================================ *
     *  §2.3.4 F-2.3.4-014 — frequency cap
     * ================================================================ */

    /**
     * Drop subscribers who were SENT any other campaign of this customer
     * inside the cap window. Only `sent` rows count — a skipped or failed
     * delivery never reached the person, so it must not suppress the next one.
     *
     * @param  Builder<Subscriber>  $query
     * @return Builder<Subscriber>
     */
    private function applyFrequencyCap(Campaign $campaign, Builder $query): Builder
    {
        $days = (int) ($campaign->frequency_cap_days ?? 0);

        if ($days < 1 || $campaign->customer_id === null) {
            return $query; // D-23: 0/null = disabled
        }

        $since = Carbon::now()->subDays($days);

        return $query->whereNotExists(function (QueryBuilder $sub) use ($campaign, $since): void {
            $sub->select(DB::raw(1))
                ->from('campaign_delivery_logs')
                ->join('campaigns as recent_campaign', 'recent_campaign.id', '=', 'campaign_delivery_logs.campaign_id')
                ->whereColumn('campaign_delivery_logs.subscriber_id', 'subscribers.id')
                ->where('recent_campaign.customer_id', $campaign->customer_id)
                ->where('campaign_delivery_logs.status', CampaignDeliveryLog::STATUS_SENT)
                ->where('campaign_delivery_logs.created_at', '>=', $since);

            if ($campaign->id !== null) {
                $sub->where('campaign_delivery_logs.campaign_id', '!=', $campaign->id);
            }
        });
    }

    /* ================================================================ *
     *  §1.5 F-1.5-012 — the subscriber's own send-frequency choice (WP-42)
     * ================================================================ */

    /**
     * Honour what the recipient asked for on the public preference centre
     * (`subscribers.send_frequency` / `frequency_paused_until`).
     *
     * The per-subscriber twin of {@see applyFrequencyCap()}: that one is the
     * SENDER's cap for one campaign, this one is the RECIPIENT's standing
     * preference, and it applies to every campaign. Both live at this choke
     * point so the wizard's count preview, the send wave and the API can
     * never disagree about who is getting this email.
     *
     * Two narrowings:
     *   1. a live pause (`paused` with no end date, or an end date still in
     *      the future) drops the subscriber outright;
     *   2. `weekly`/`monthly` drop anyone who already received a campaign
     *      from this customer inside their own window. NULL frequency means
     *      "never asked" and is treated as `every_send`, so pre-existing
     *      subscribers are completely unaffected.
     *
     * @param  Builder<Subscriber>  $query
     * @return Builder<Subscriber>
     */
    private function applySubscriberFrequency(Campaign $campaign, Builder $query): Builder
    {
        $now = Carbon::now();

        // (1) An active pause. `!=` alone would drop NULL rows in SQL, so the
        // predicate is written as "not (paused and still paused)".
        $query->where(fn (Builder $outer): Builder => $outer
            ->where('subscribers.send_frequency', '!=', SendFrequency::Paused->value)
            ->orWhereNull('subscribers.send_frequency')
            ->orWhere(fn (Builder $lapsed): Builder => $lapsed
                ->whereNotNull('subscribers.frequency_paused_until')
                ->where('subscribers.frequency_paused_until', '<=', $now)));

        if ($campaign->customer_id === null) {
            return $query;
        }

        // (2) The throttling frequencies, one predicate each — the day count
        // comes from the subscriber's row, not from the campaign.
        foreach (SendFrequency::cases() as $frequency) {
            $days = $frequency->minimumDaysBetweenSends();

            if ($days < 1) {
                continue;
            }

            $since = $now->copy()->subDays($days);

            $query->where(fn (Builder $outer): Builder => $outer
                ->where('subscribers.send_frequency', '!=', $frequency->value)
                ->orWhereNull('subscribers.send_frequency')
                ->orWhereNotExists(function (QueryBuilder $sub) use ($campaign, $since): void {
                    $sub->select(DB::raw(1))
                        ->from('campaign_delivery_logs')
                        ->join('campaigns as freq_campaign', 'freq_campaign.id', '=', 'campaign_delivery_logs.campaign_id')
                        ->whereColumn('campaign_delivery_logs.subscriber_id', 'subscribers.id')
                        ->where('freq_campaign.customer_id', $campaign->customer_id)
                        ->where('campaign_delivery_logs.status', CampaignDeliveryLog::STATUS_SENT)
                        ->where('campaign_delivery_logs.created_at', '>=', $since);

                    if ($campaign->id !== null) {
                        $sub->where('campaign_delivery_logs.campaign_id', '!=', $campaign->id);
                    }
                }));
        }

        return $query;
    }

    /* ================================================================ *
     *  §4.5 F-4.5-003 — CASL / country-specific consent (WP-42)
     * ================================================================ */

    /**
     * Drop recipients whose consent is not valid under the law governing the
     * campaign's list (see {@see ConsentRules} for how the law is chosen).
     *
     * EXPRESS consent = `subscribers.confirm_ip`, written only by the public
     * double opt-in confirm route (F-4.5-004). Anyone without it arrived by
     * single opt-in, import, API or an operator — implied consent, which:
     *   • a regime with an implied window keeps for that many days from
     *     `subscribed_at` (CASL: 730), then drops;
     *   • a regime that forbids implied consent drops immediately.
     * A subscriber with no subscribed_at cannot be dated, so under a regime
     * with a clock they are dropped rather than mailed on an unprovable
     * consent — the direction that cannot produce an unlawful send.
     *
     * Off unless `emp-consent.enforce`, exactly like the F-4.5-002 gate:
     * switching it on removes people from sends, which must be a decision.
     *
     * @param  Builder<Subscriber>  $query
     * @return Builder<Subscriber>
     */
    private function applyConsentRules(Campaign $campaign, Builder $query): Builder
    {
        $rules = app(ConsentRules::class);

        if (! $rules->enforced()) {
            return $query;
        }

        $rule = $rules->forCampaign($campaign);

        if (! $rule->requiresExpressConsent) {
            return $query;
        }

        if ($rule->forbidsImpliedConsent()) {
            return $query->whereNotNull('subscribers.confirm_ip');
        }

        $cutoff = $rules->impliedConsentCutoff($rule);

        return $query->where(function (Builder $outer) use ($cutoff): void {
            $outer->whereNotNull('subscribers.confirm_ip')
                ->orWhere(fn (Builder $implied) => $implied
                    ->whereNotNull('subscribers.subscribed_at')
                    ->where('subscribers.subscribed_at', '>=', $cutoff));
        });
    }

    /* ================================================================ *
     *  WP-16 suppression (unchanged)
     * ================================================================ */

    /**
     * WP-16 suppression subtraction (§2.9.11): drop subscribers whose
     * address appears on any attached suppression list. Lists are re-fenced
     * to the campaign's own customer so a foreign id in the json column can
     * never influence targeting.
     *
     * @param  Builder<Subscriber>  $query
     * @return Builder<Subscriber>
     */
    private function subtractSuppressionLists(Campaign $campaign, Builder $query): Builder
    {
        $ids = array_values(array_filter(array_map(
            intval(...),
            (array) ($campaign->suppression_list_ids ?? []),
        )));

        if ($ids === []) {
            return $query;
        }

        return $query->whereNotExists(function (QueryBuilder $sub) use ($campaign, $ids): void {
            $sub->select(DB::raw(1))
                ->from('suppression_list_emails')
                ->join('suppression_lists', 'suppression_lists.id', '=', 'suppression_list_emails.suppression_list_id')
                ->whereIn('suppression_list_emails.suppression_list_id', $ids)
                ->where('suppression_lists.customer_id', $campaign->customer_id)
                ->whereColumn('suppression_list_emails.email', 'subscribers.email');
        });
    }
}
