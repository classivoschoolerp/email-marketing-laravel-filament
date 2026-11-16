<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;

/**
 * Canonical automation event names (WP-21). Model hooks (AutomationHooks)
 * record these; TriggerMatcher maps them onto trigger node types and
 * wait-for-event nodes park on them. Custom events arriving through the
 * per-automation inbound hook endpoint use the `custom:` prefix.
 */
final class AutomationEvents
{
    public const SUBSCRIBED = 'subscribed';

    public const UNSUBSCRIBED = 'unsubscribed';

    public const TAG_ADDED = 'tag_added';

    public const TAG_REMOVED = 'tag_removed';

    public const FIELD_CHANGED = 'field_changed';

    public const CAMPAIGN_OPENED = 'campaign_opened';

    public const LINK_CLICKED = 'link_clicked';

    public const FORM_SUBMITTED = 'form_submitted';

    public const SURVEY_COMPLETED = 'survey_completed';

    /**
     * WP-44 (§2.13 CRM): the two producers F-2.4.2-017 names. Both are raised
     * by App\Services\Crm — `deal_stage_changed` by DealService the moment a
     * `crm_deal_stage_events` row is written, `lead_score_threshold` by
     * LeadScorer whenever a recompute moves a contact's number. Neither is
     * inbound-allowed (D-92): a caller must not be able to forge a deal move.
     */
    public const DEAL_STAGE_CHANGED = 'deal_stage_changed';

    public const LEAD_SCORE_THRESHOLD = 'lead_score_threshold';

    /**
     * WP-45 (§2.12 ecommerce + site tracking): the six producers F-2.4.2-015
     * and F-2.4.2-016 name. All are raised by App\Services\Ecommerce —
     * order/back-in-stock by StoreSyncService as it writes, `cart_abandoned`
     * by the abandoned-cart tick, and the two site-tracking events by the
     * public collector. None is inbound-allowed (D-92): a caller must not be
     * able to forge an order and, through it, revenue.
     */
    public const ORDER_PLACED = 'order_placed';

    public const ORDER_SHIPPED = 'order_shipped';

    public const CART_ABANDONED = 'cart_abandoned';

    public const PRODUCT_VIEWED = 'product_viewed';

    public const BACK_IN_STOCK = 'back_in_stock';

    public const PAGE_VISITED = 'page_visited';

    public const CUSTOM_PREFIX = 'custom:';

    /** Events a wait-for-event / delay-until-event node may park on. */
    public const WAITABLE = [
        self::SUBSCRIBED,
        self::UNSUBSCRIBED,
        self::TAG_ADDED,
        self::TAG_REMOVED,
        self::FIELD_CHANGED,
        self::CAMPAIGN_OPENED,
        self::LINK_CLICKED,
        self::FORM_SUBMITTED,
        self::SURVEY_COMPLETED,
        self::DEAL_STAGE_CHANGED,
        self::LEAD_SCORE_THRESHOLD,
        // WP-45 §2.12: a post-purchase journey waiting for the shipment, and
        // a browse-abandonment journey waiting for the order that never came,
        // are both wait-nodes on these names.
        self::ORDER_PLACED,
        self::ORDER_SHIPPED,
        self::CART_ABANDONED,
        self::PRODUCT_VIEWED,
        self::BACK_IN_STOCK,
        self::PAGE_VISITED,
    ];

    /**
     * Canonical event names an EXTERNAL caller may raise through the
     * per-automation inbound hook (F-2.4.2-014).
     *
     * Deliberately only the two whose own producer module does not exist in
     * the product yet: F-2.4.2-011 "submitted a form / landing page" (the
     * §2.7 forms + landing-page builder is P2) and F-2.4.2-012 "completed a
     * survey" (§2.8 surveys is P2). Until those modules ship, a third-party
     * form/survey tool drives the triggers over this hook.
     *
     * Everything else stays namespaced under `custom:` so an inbound caller
     * can never forge a lifecycle event such as subscribed/unsubscribed.
     */
    public const INBOUND_ALLOWED = [
        self::FORM_SUBMITTED,
        self::SURVEY_COMPLETED,
    ];

    /**
     * Map an inbound hook `event` value onto the name the automation engine
     * listens on: a whitelisted canonical event passes through, anything else
     * becomes a `custom:` event.
     */
    public static function normalizeInbound(string $event): string
    {
        $event = mb_strtolower(trim($event));

        return in_array($event, self::INBOUND_ALLOWED, true)
            ? $event
            : self::CUSTOM_PREFIX.$event;
    }

    /** @return list<AutomationNodeType> trigger node types fed by an event */
    public static function triggerTypesFor(string $event): array
    {
        return match ($event) {
            self::SUBSCRIBED => [AutomationNodeType::TriggerSubscribed],
            self::UNSUBSCRIBED => [AutomationNodeType::TriggerUnsubscribed],
            self::TAG_ADDED => [AutomationNodeType::TriggerTagAdded],
            self::TAG_REMOVED => [AutomationNodeType::TriggerTagRemoved],
            self::FIELD_CHANGED => [AutomationNodeType::TriggerFieldChanged],
            self::CAMPAIGN_OPENED => [AutomationNodeType::TriggerCampaignOpened],
            self::LINK_CLICKED => [AutomationNodeType::TriggerLinkClicked],
            self::FORM_SUBMITTED => [AutomationNodeType::TriggerFormSubmitted],
            self::SURVEY_COMPLETED => [AutomationNodeType::TriggerSurveyCompleted],
            // WP-44 §2.13 / F-2.4.2-017.
            self::DEAL_STAGE_CHANGED => [AutomationNodeType::TriggerDealStageChanged],
            self::LEAD_SCORE_THRESHOLD => [AutomationNodeType::TriggerLeadScore],
            // WP-45 §2.12 / F-2.4.2-015 + F-2.4.2-016.
            self::ORDER_PLACED => [AutomationNodeType::TriggerOrderPlaced],
            self::ORDER_SHIPPED => [AutomationNodeType::TriggerOrderShipped],
            self::CART_ABANDONED => [AutomationNodeType::TriggerCartAbandoned],
            self::PRODUCT_VIEWED => [AutomationNodeType::TriggerProductViewed],
            self::BACK_IN_STOCK => [AutomationNodeType::TriggerBackInStock],
            self::PAGE_VISITED => [AutomationNodeType::TriggerPageVisited],
            default => [],
        };
    }

    /** @return array<string, string> value => label, for wait-node forms */
    public static function options(): array
    {
        $options = [];

        foreach (self::WAITABLE as $event) {
            $options[$event] = __('automations.events.'.$event);
        }

        return $options;
    }
}
