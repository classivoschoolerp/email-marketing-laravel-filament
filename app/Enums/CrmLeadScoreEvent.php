<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * §2.13 F-2.13-007 — the fact a lead-scoring rule scores.
 *
 * Two families, and the split is what makes "rules-based + engagement decay"
 * a real sentence rather than a slogan:
 *
 *  BEHAVIOURAL (`decays()` = true) — opens, clicks, bounces, complaints and
 *  the engagement rating. These are multiplied by the RECENCY-WEIGHTED counts
 *  App\Services\Subscribers\EngagementScorer already computes (age ≤30d → 1.0,
 *  ≤90d → 0.6, ≤180d → 0.3, ≤365d → 0.1, older → dropped). A contact who
 *  stops engaging therefore loses points with no cron doing anything: the
 *  next recompute simply weighs the same events lower. WP-42 owns that curve
 *  and this module does not fork it.
 *
 *  ATTRIBUTIVE (`decays()` = false) — a tag, a lifecycle stage, a company, a
 *  list membership, an open or won deal. Facts, not activity. They must NOT
 *  decay: a closed-won customer does not become less of a customer because
 *  nobody mailed them for a quarter.
 *
 * Each case documents the `config` keys its rule row understands.
 */
enum CrmLeadScoreEvent: string
{
    /** points × weighted opens across every subscriber row of the contact. */
    case EmailOpen = 'email_open';

    /** points × weighted clicks. */
    case EmailClick = 'email_click';

    /** points × in-window bounces (use negative points). */
    case EmailBounce = 'email_bounce';

    /** points × in-window spam complaints (use negative points). */
    case EmailComplaint = 'email_complaint';

    /** points × (best engagement score / 100) — 0..points, decayed by WP-42. */
    case EngagementScore = 'engagement_score';

    /** config.tag — points once if any subscriber row carries the tag. */
    case TagPresent = 'tag_present';

    /** config.stage — points once when the contact sits in that stage. */
    case LifecycleStage = 'lifecycle_stage';

    /** config.list_id — points once if the contact is on that list. */
    case ListMember = 'list_member';

    /** points once when the contact is attached to a company. */
    case HasCompany = 'has_company';

    /** points × number of OPEN deals on the contact. */
    case DealOpen = 'deal_open';

    /** points × number of WON deals on the contact. */
    case DealWon = 'deal_won';

    public function label(): string
    {
        return __('crm.score_event.'.$this->value);
    }

    /** Is this rule weighted by engagement recency (WP-42's decay curve)? */
    public function decays(): bool
    {
        return in_array($this, [
            self::EmailOpen,
            self::EmailClick,
            self::EmailBounce,
            self::EmailComplaint,
            self::EngagementScore,
        ], true);
    }

    /** The `config` key this rule needs, if any. */
    public function configKey(): ?string
    {
        return match ($this) {
            self::TagPresent => 'tag',
            self::LifecycleStage => 'stage',
            self::ListMember => 'list_id',
            default => null,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
