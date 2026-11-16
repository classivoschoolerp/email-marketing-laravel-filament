<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\SegmentConditionType;
use App\Enums\SegmentMatchType;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignTrackClick;
use App\Models\CampaignTrackOpen;
use App\Models\EmailList;
use App\Models\Subscriber;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Evaluates the §2.2.10 segment condition vocabulary against a SINGLE
 * subscriber (F-2.4.2-036 if/else, F-2.4.2-039 goal). Field/date/status
 * families reuse SegmentQueryBuilder constrained to the subscriber's key;
 * campaign engagement conditions (opened / clicked / not …) are evaluated
 * directly against the WP-09 tracking tables because the segment builder's
 * campaign family is a documented 1=0 stub.
 *
 * Condition rows: {type, list_field_id, field_key, operator, value, value2,
 * settings: {campaign_id, url, within_days}}.
 */
class ConditionEvaluator
{
    public function __construct(private readonly SegmentQueryBuilder $segments) {}

    /** @param list<array<string, mixed>> $conditions */
    public function matches(Subscriber $subscriber, SegmentMatchType|string $match, array $conditions): bool
    {
        $match = $match instanceof SegmentMatchType ? $match : SegmentMatchType::from((string) $match);

        $conditions = array_values(array_filter(
            $conditions,
            fn (array $condition): bool => ($condition['operator'] ?? '') !== '',
        ));

        if ($conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            $result = $this->matchesOne($subscriber, $condition);

            if ($match === SegmentMatchType::Any && $result) {
                return true;
            }

            if ($match === SegmentMatchType::All && ! $result) {
                return false;
            }
        }

        return $match === SegmentMatchType::All;
    }

    /** @param array<string, mixed> $condition */
    public function matchesOne(Subscriber $subscriber, array $condition): bool
    {
        $type = SegmentConditionType::tryFrom((string) ($condition['type'] ?? 'field')) ?? SegmentConditionType::Field;

        if ($type === SegmentConditionType::Campaign) {
            return $this->matchesCampaign($subscriber, $condition);
        }

        $list = EmailList::withoutTenancy()->find($subscriber->list_id);

        if ($list === null) {
            return false;
        }

        return $this->segments
            ->build($list, SegmentMatchType::All, [$condition])
            ->withoutGlobalScopes()
            ->whereKey($subscriber->id)
            ->exists();
    }

    /** @param array<string, mixed> $condition */
    private function matchesCampaign(Subscriber $subscriber, array $condition): bool
    {
        $operator = (string) ($condition['operator'] ?? '');
        $settings = (array) ($condition['settings'] ?? []);
        $campaignUid = $this->campaignUid($settings);
        $withinDays = (int) ($settings['within_days'] ?? 0);
        $since = $withinDays > 0 ? Carbon::now()->subDays($withinDays) : null;

        $opens = CampaignTrackOpen::query()->where('subscriber_uid', $subscriber->uid);
        $clicks = CampaignTrackClick::query()->where('subscriber_uid', $subscriber->uid);

        if ($campaignUid !== null) {
            $opens->where('campaign_uid', $campaignUid);
            $clicks->where('campaign_uid', $campaignUid);
        }

        if ($since !== null) {
            $opens->where('created_at', '>=', $since);
            $clicks->where('created_at', '>=', $since);
        }

        return match ($operator) {
            'opened' => $opens->exists(),
            'not_opened' => $this->wasSent($subscriber, $settings) && ! $opens->exists(),
            'clicked_any' => $clicks->exists(),
            'clicked_link' => $clicks->whereHas('url', fn ($q) => $q->where('url', 'like', '%'.addcslashes((string) ($settings['url'] ?? ''), '%_\\').'%'))->exists(),
            'not_clicked' => ! $clicks->exists(),
            default => false,
        };
    }

    /** @param array<string, mixed> $settings */
    private function wasSent(Subscriber $subscriber, array $settings): bool
    {
        $campaignId = (int) ($settings['campaign_id'] ?? 0);

        if ($campaignId <= 0) {
            return true; // "not opened anything" — don't require a send record
        }

        return CampaignDeliveryLog::query()
            ->where('campaign_id', $campaignId)
            ->where('subscriber_id', $subscriber->id)
            ->where('status', CampaignDeliveryLog::STATUS_SENT)
            ->exists();
    }

    /** @param array<string, mixed> $settings */
    private function campaignUid(array $settings): ?string
    {
        $campaignId = (int) ($settings['campaign_id'] ?? 0);

        if ($campaignId <= 0) {
            return null;
        }

        $uid = Campaign::withoutTenancy()->withTrashed()->whereKey($campaignId)->value('uid');

        return $uid !== null ? (string) $uid : '__missing__';
    }
}
