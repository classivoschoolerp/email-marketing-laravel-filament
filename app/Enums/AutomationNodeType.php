<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The §2.4.2 node set: triggers (F-2.4.2-005..014, 018, 019), actions
 * (F-2.4.2-020, 024..030) and logic nodes (F-2.4.2-034..043).
 *
 * WP-32 added `action.sms`, the multichannel journey node of §2.10
 * F-2.10-012 ("email → wait → SMS if unopened"): the P2 SMS module now
 * exists, so the node has a real executor and a real gateway behind it.
 *
 * WP-44 added the five CRM nodes, for the same reason: §2.13 now exists, so
 * F-2.4.2-017 (deal stage changed / lead score threshold), F-2.4.2-031
 * (create CRM task / deal / update deal stage), F-2.4.2-032 (adjust lead
 * score) and F-2.4.2-033 (assign to owner) have real storage and real
 * services behind them.
 *
 * WP-45 added the six ecommerce/site-tracking triggers for the same reason
 * again: §2.12 now exists, so F-2.4.2-015 (cart abandoned, order placed,
 * order shipped, product viewed, back in stock) and F-2.4.2-016 (page
 * visited) have real producers — StoreSyncService, the abandoned-cart tick
 * and the site-tracking collector.
 *
 * WP-46 added `action.whatsapp` (F-2.4.2-022) and `action.web_push`
 * (F-2.4.2-023), once more for the same reason: the §2.10 WhatsApp and
 * web-push modules now exist, so both nodes have a real executor, a real
 * queued job and a real provider behind them.
 *
 * Branch keys per type are defined in branches(); dynamic-branch types
 * (splits) enumerate keys from their config.
 */
enum AutomationNodeType: string
{
    // Triggers
    case TriggerSubscribed = 'trigger.subscribed';
    case TriggerUnsubscribed = 'trigger.unsubscribed';
    case TriggerTagAdded = 'trigger.tag_added';
    case TriggerTagRemoved = 'trigger.tag_removed';
    case TriggerFieldChanged = 'trigger.field_changed';
    case TriggerCampaignOpened = 'trigger.campaign_opened';
    case TriggerLinkClicked = 'trigger.link_clicked';
    case TriggerCampaignNotOpened = 'trigger.campaign_not_opened';
    case TriggerFormSubmitted = 'trigger.form_submitted';
    case TriggerSurveyCompleted = 'trigger.survey_completed';
    case TriggerDate = 'trigger.date';
    case TriggerWebhook = 'trigger.webhook';
    case TriggerSegmentEntered = 'trigger.segment_entered';
    case TriggerSegmentExited = 'trigger.segment_exited';
    case TriggerManual = 'trigger.manual';
    // WP-44 §2.13 CRM (F-2.4.2-017).
    case TriggerDealStageChanged = 'trigger.deal_stage_changed';
    case TriggerLeadScore = 'trigger.lead_score';
    // WP-45 §2.12 ecommerce (F-2.4.2-015) + site tracking (F-2.4.2-016).
    case TriggerCartAbandoned = 'trigger.cart_abandoned';
    case TriggerOrderPlaced = 'trigger.order_placed';
    case TriggerOrderShipped = 'trigger.order_shipped';
    case TriggerProductViewed = 'trigger.product_viewed';
    case TriggerBackInStock = 'trigger.back_in_stock';
    case TriggerPageVisited = 'trigger.page_visited';

    // Actions
    case ActionSendEmail = 'action.send_email';
    case ActionNotifyTeam = 'action.notify_team';
    case ActionAddTag = 'action.add_tag';
    case ActionRemoveTag = 'action.remove_tag';
    case ActionUpdateField = 'action.update_field';
    case ActionList = 'action.list';
    case ActionSuppress = 'action.suppress';
    case ActionCrossAutomation = 'action.cross_automation';
    case ActionWebhook = 'action.webhook';
    case ActionSms = 'action.sms';
    // WP-46 §2.10 multichannel journey nodes: F-2.4.2-022, F-2.4.2-023.
    case ActionWhatsApp = 'action.whatsapp';
    case ActionWebPush = 'action.web_push';
    // WP-44 §2.13 CRM: F-2.4.2-031, -032, -033.
    case ActionCrm = 'action.crm';
    case ActionLeadScore = 'action.lead_score';
    case ActionAssignOwner = 'action.assign_owner';

    // Logic
    case LogicDelay = 'logic.delay';
    case LogicWaitEvent = 'logic.wait_event';
    case LogicIfElse = 'logic.if_else';
    case LogicSplit = 'logic.split';
    case LogicPercentage = 'logic.percentage';
    case LogicGoal = 'logic.goal';
    case LogicExit = 'logic.exit';
    case LogicJump = 'logic.jump';
    case LogicFrequencyGuard = 'logic.frequency_guard';

    public function label(): string
    {
        return __('automations.node_type.'.str_replace('.', '_', $this->value));
    }

    public function isTrigger(): bool
    {
        return str_starts_with($this->value, 'trigger.');
    }

    public function isAction(): bool
    {
        return str_starts_with($this->value, 'action.');
    }

    public function isLogic(): bool
    {
        return str_starts_with($this->value, 'logic.');
    }

    /** Trigger types the every-minute tick polls (no push event exists). */
    public function isPolledTrigger(): bool
    {
        return in_array($this, [
            self::TriggerDate,
            self::TriggerCampaignNotOpened,
            self::TriggerSegmentEntered,
            self::TriggerSegmentExited,
        ], true);
    }

    /** @return list<self> */
    public static function triggers(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case): bool => $case->isTrigger()));
    }

    /** @return list<self> */
    public static function actions(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case): bool => $case->isAction()));
    }

    /** @return list<self> */
    public static function logic(): array
    {
        return array_values(array_filter(self::cases(), fn (self $case): bool => $case->isLogic()));
    }

    /**
     * Static branch keys for the type; splits return [] and enumerate their
     * keys from config (see AutomationNode::branchKeys()).
     *
     * @return list<string>
     */
    public function branches(): array
    {
        return match ($this) {
            self::LogicIfElse => ['yes', 'no'],
            self::LogicWaitEvent => ['event', 'timeout'],
            self::LogicExit, self::LogicJump => [],
            self::LogicSplit, self::LogicPercentage => [],
            default => ['next'],
        };
    }

    /** @param list<self> $cases @return array<string, string> */
    public static function optionsFor(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
