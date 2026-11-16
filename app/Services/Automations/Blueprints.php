<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Models\Customer;

/**
 * F-2.4.1-004 — pre-built journey templates gallery.
 *
 * Ships ALL 11 spec-listed blueprints.
 *
 * WP-21 shipped Welcome series, Re-engagement, Birthday and Onboarding drip;
 * WP-27C added Win-back, Lead nurture and Anniversary — every blueprint that
 * could be expressed with the P1 trigger set.
 *
 * The remaining 4 — Abandoned cart, Post-purchase, Browse abandonment,
 * Back-in-stock — were deliberately withheld until WP-45, because each one is
 * DEFINED by an ecommerce or site-tracking trigger that did not exist:
 * F-2.4.2-015 (cart abandoned / order placed / order shipped / product viewed
 * / back-in-stock) and F-2.4.2-016 (page visited). Faking them with a tag or
 * webhook trigger would have been a silent simplification. §2.12 landed those
 * producers, so the four ship here using exactly those triggers and nothing
 * else.
 *
 * Blueprints instantiate as DRAFTS with placeholder config (list / segment /
 * campaign / date-field / store pickers empty); publish-time validation
 * (GraphValidator) then walks the user through the keys they must choose.
 */
class Blueprints
{
    public const KEYS = [
        'welcome_series',
        'reengagement',
        'birthday',
        'onboarding_drip',
        'win_back',
        'lead_nurture',
        'anniversary',
        // WP-45 §2.12 — the four that needed the ecommerce triggers.
        'abandoned_cart',
        'post_purchase',
        'browse_abandonment',
        'back_in_stock',
    ];

    /**
     * Blueprints that re-run for the same contact every occurrence (the
     * date-driven ones); everything else enters once.
     */
    private const MULTI_ENTRY = [
        'birthday',
        'anniversary',
        // WP-45: a shopper abandons more than one cart in a lifetime, buys
        // more than once and browses more than once. Entering these ONCE
        // would silently stop recovering the second cart — which is precisely
        // the money these journeys exist to recover.
        'abandoned_cart',
        'post_purchase',
        'browse_abandonment',
        'back_in_stock',
    ];

    /** @return array<string, array{name: string, description: string}> */
    public static function options(): array
    {
        $options = [];

        foreach (self::KEYS as $key) {
            $options[$key] = [
                'name' => __('automations.blueprints.'.$key.'.name'),
                'description' => __('automations.blueprints.'.$key.'.description'),
            ];
        }

        return $options;
    }

    /** Create a draft automation from a blueprint canvas. */
    public function instantiate(string $key, Customer $customer): Automation
    {
        $canvas = $this->canvas($key);

        return Automation::withoutTenancy()->create([
            'customer_id' => $customer->id,
            'name' => __('automations.blueprints.'.$key.'.name'),
            'status' => AutomationStatus::Draft,
            'canvas' => $canvas,
            'reentry_mode' => in_array($key, self::MULTI_ENTRY, true)
                ? Automation::REENTRY_MULTIPLE
                : Automation::REENTRY_ONCE,
        ]);
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    public function canvas(string $key): array
    {
        return match ($key) {
            'reengagement' => $this->reengagement(),
            'birthday' => $this->birthday(),
            'onboarding_drip' => $this->onboardingDrip(),
            'win_back' => $this->winBack(),
            'lead_nurture' => $this->leadNurture(),
            'anniversary' => $this->anniversary(),
            // WP-45 §2.12 (F-2.4.1-004, unblocked by F-2.4.2-015/016).
            'abandoned_cart' => $this->abandonedCart(),
            'post_purchase' => $this->postPurchase(),
            'browse_abandonment' => $this->browseAbandonment(),
            'back_in_stock' => $this->backInStock(),
            default => $this->welcomeSeries(),
        };
    }

    /**
     * Abandoned cart (F-2.4.1-004) — the §2.12 F-2.12-004 journey.
     *
     * Trigger: `cart_abandoned` (F-2.4.2-015). Both emails carry the CART
     * CONTENTS BLOCK, which is the whole point: a recovery email that does not
     * show what was left behind is just a nag.
     *
     * The wait node parks on `order_placed`, so a shopper who checks out
     * before the second nudge leaves the journey through the `event` branch
     * and is never chased for a purchase they already made.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function abandonedCart(): array
    {
        $cartBlock = '<div data-emp-ecom="cart" data-limit="5"></div>';

        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerCartAbandoned, [
                'store_connection_id' => null,
                'min_total' => 0,
            ], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::LogicDelay, [
                'mode' => 'duration', 'value' => 1, 'unit' => 'hours',
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.abandoned_cart.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.abandoned_cart.email1_body').'</p>'.$cartBlock,
            ], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::LogicWaitEvent, [
                'event' => AutomationEvents::ORDER_PLACED,
                'timeout_value' => 24,
                'timeout_unit' => 'hours',
            ], ['event' => 'n7', 'timeout' => 'n5']),
            $this->node('n5', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.abandoned_cart.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.abandoned_cart.email2_body').'</p>'.$cartBlock,
            ], ['next' => 'n6']),
            $this->node('n6', AutomationNodeType::ActionAddTag, ['tag' => 'cart-abandoned'], ['next' => 'n8']),
            $this->node('n7', AutomationNodeType::ActionAddTag, ['tag' => 'cart-recovered'], ['next' => 'n8']),
            $this->node('n8', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /**
     * Post-purchase (F-2.4.1-004) — thank you, then shipping, then a
     * cross-sell.
     *
     * Trigger: `order_placed`. The wait node parks on `order_shipped`, so the
     * "it's on its way" email is sent by the SHIPMENT, not by a guessed delay
     * — and a slow shipment simply delays it rather than lying about it. The
     * timeout branch skips straight to the cross-sell, so an order that never
     * ships (a digital product) still finishes the journey.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function postPurchase(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerOrderPlaced, [
                'store_connection_id' => null,
                'min_total' => 0,
            ], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.post_purchase.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.post_purchase.email1_body').'</p>',
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::LogicWaitEvent, [
                'event' => AutomationEvents::ORDER_SHIPPED,
                'timeout_value' => 7,
                'timeout_unit' => 'days',
            ], ['event' => 'n4', 'timeout' => 'n5']),
            $this->node('n4', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.post_purchase.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.post_purchase.email2_body').'</p>',
            ], ['next' => 'n5']),
            $this->node('n5', AutomationNodeType::LogicDelay, [
                'mode' => 'duration', 'value' => 14, 'unit' => 'days',
            ], ['next' => 'n6']),
            $this->node('n6', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.post_purchase.email3_subject'),
                'content_html' => '<p>'.__('automations.blueprints.post_purchase.email3_body').'</p>'
                    .'<div data-emp-ecom="recommendations" data-strategy="best_sellers" data-limit="3"></div>',
            ], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::ActionAddTag, ['tag' => 'customer'], ['next' => 'n8']),
            $this->node('n8', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /**
     * Browse abandonment (F-2.4.1-004) — they looked and left.
     *
     * Trigger: `product_viewed` (F-2.4.2-015), which the §2.12 site-tracking
     * script produces. The wait node parks on `order_placed` FIRST: a viewer
     * who buys within the hour must never receive "still thinking about it?".
     *
     * The email carries the RECENTLY VIEWED recommendation block, so it shows
     * the products this recipient actually looked at rather than a generic
     * best-seller grid.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function browseAbandonment(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerProductViewed, [
                'store_connection_id' => null,
                'product_external_id' => '',
                'category' => '',
            ], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::LogicWaitEvent, [
                'event' => AutomationEvents::ORDER_PLACED,
                'timeout_value' => 4,
                'timeout_unit' => 'hours',
            ], ['event' => 'n5', 'timeout' => 'n3']),
            $this->node('n3', AutomationNodeType::LogicFrequencyGuard, [
                'quiet_enabled' => true, 'quiet_start' => '21:00', 'quiet_end' => '09:00',
                'cap_enabled' => true, 'cap_count' => 1, 'cap_days' => 3,
            ], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.browse_abandonment.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.browse_abandonment.email1_body').'</p>'
                    .'<div data-emp-ecom="recommendations" data-strategy="recently_viewed" data-limit="3"></div>',
            ], ['next' => 'n5']),
            $this->node('n5', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /**
     * Back in stock (F-2.4.1-004).
     *
     * Trigger: `back_in_stock` (F-2.4.2-015). The producer only raises it for
     * contacts with a RECORDED interest in that product — viewers and
     * cart-leavers (see EcommerceEvents::interestedIn) — so this journey mails
     * people who asked, not a whole list.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function backInStock(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerBackInStock, [
                'store_connection_id' => null,
                'product_external_id' => '',
                'category' => '',
            ], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::LogicFrequencyGuard, [
                'quiet_enabled' => true, 'quiet_start' => '21:00', 'quiet_end' => '09:00',
                'cap_enabled' => false,
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.back_in_stock.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.back_in_stock.email1_body').'</p>'
                    .'<div data-emp-ecom="recommendations" data-strategy="recently_viewed" data-limit="3"></div>',
            ], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::LogicWaitEvent, [
                'event' => AutomationEvents::ORDER_PLACED,
                'timeout_value' => 3,
                'timeout_unit' => 'days',
            ], ['event' => 'n6', 'timeout' => 'n5']),
            $this->node('n5', AutomationNodeType::ActionAddTag, ['tag' => 'restock-notified'], ['next' => 'n7']),
            $this->node('n6', AutomationNodeType::ActionAddTag, ['tag' => 'restock-converted'], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private function welcomeSeries(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerSubscribed, ['list_id' => null], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.welcome_series.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.welcome_series.email1_body').'</p>',
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 2, 'unit' => 'days'], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.welcome_series.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.welcome_series.email2_body').'</p>',
            ], ['next' => 'n5']),
            $this->node('n5', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 4, 'unit' => 'days'], ['next' => 'n6']),
            $this->node('n6', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.welcome_series.email3_subject'),
                'content_html' => '<p>'.__('automations.blueprints.welcome_series.email3_body').'</p>',
            ], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private function reengagement(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerSegmentEntered, ['segment_id' => null], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.reengagement.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.reengagement.email1_body').'</p>',
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::LogicWaitEvent, [
                'event' => AutomationEvents::CAMPAIGN_OPENED,
                'timeout_value' => 7,
                'timeout_unit' => 'days',
            ], ['event' => 'n6', 'timeout' => 'n4']),
            $this->node('n4', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.reengagement.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.reengagement.email2_body').'</p>',
            ], ['next' => 'n5']),
            $this->node('n5', AutomationNodeType::ActionAddTag, ['tag' => 'inactive'], ['next' => 'n7']),
            $this->node('n6', AutomationNodeType::ActionAddTag, ['tag' => 'reengaged'], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private function birthday(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerDate, ['list_field_id' => null, 'offset_days' => 0, 'annual' => true], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::LogicFrequencyGuard, [
                'quiet_enabled' => true, 'quiet_start' => '21:00', 'quiet_end' => '09:00',
                'cap_enabled' => false,
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.birthday.email_subject'),
                'content_html' => '<p>'.__('automations.blueprints.birthday.email_body').'</p>',
            ], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /** @return array{nodes: list<array<string, mixed>>} */
    private function onboardingDrip(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerSubscribed, ['list_id' => null], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.onboarding_drip.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.onboarding_drip.email1_body').'</p>',
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 1, 'unit' => 'days'], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::LogicIfElse, [
                'match' => 'all',
                'conditions' => [
                    ['type' => 'campaign', 'operator' => 'opened', 'settings' => ['within_days' => 7]],
                ],
            ], ['yes' => 'n5', 'no' => 'n6']),
            $this->node('n5', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.onboarding_drip.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.onboarding_drip.email2_body').'</p>',
            ], ['next' => 'n7']),
            $this->node('n6', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.onboarding_drip.email2_alt_subject'),
                'content_html' => '<p>'.__('automations.blueprints.onboarding_drip.email2_alt_body').'</p>',
            ], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 3, 'unit' => 'days'], ['next' => 'n8']),
            $this->node('n8', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.onboarding_drip.email3_subject'),
                'content_html' => '<p>'.__('automations.blueprints.onboarding_drip.email3_body').'</p>',
            ], ['next' => 'n9']),
            $this->node('n9', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /**
     * Win-back (F-2.4.1-004) — built on F-2.4.2-010 "did NOT open a campaign
     * after N days", NOT on an ecommerce purchase trigger: a contact who has
     * gone quiet on a chosen campaign gets one offer, and the journey then
     * waits for a click to decide which tag they leave with.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function winBack(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerCampaignNotOpened, [
                'campaign_id' => null,
                'days' => 30,
            ], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.win_back.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.win_back.email1_body').'</p>',
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::LogicWaitEvent, [
                'event' => AutomationEvents::LINK_CLICKED,
                'timeout_value' => 10,
                'timeout_unit' => 'days',
            ], ['event' => 'n6', 'timeout' => 'n4']),
            $this->node('n4', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.win_back.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.win_back.email2_body').'</p>',
            ], ['next' => 'n5']),
            $this->node('n5', AutomationNodeType::ActionAddTag, ['tag' => 'win-back-lapsed'], ['next' => 'n7']),
            $this->node('n6', AutomationNodeType::ActionAddTag, ['tag' => 'win-back-recovered'], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /**
     * Lead nurture (F-2.4.1-004) — tag-driven (F-2.4.2-007) education drip
     * that branches on engagement and closes on a goal node
     * (F-2.4.2-039): a click inside the window exits the journey converted.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function leadNurture(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerTagAdded, ['tag' => 'lead'], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::LogicGoal, [
                'match' => 'any',
                'conditions' => [
                    ['type' => 'campaign', 'operator' => 'clicked_any', 'settings' => ['within_days' => 30]],
                ],
                'conversion_value' => 0,
                'wait' => false,
                'continuous' => true,
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.lead_nurture.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.lead_nurture.email1_body').'</p>',
            ], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 3, 'unit' => 'days'], ['next' => 'n5']),
            $this->node('n5', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.lead_nurture.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.lead_nurture.email2_body').'</p>',
            ], ['next' => 'n6']),
            $this->node('n6', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 4, 'unit' => 'days'], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::LogicIfElse, [
                'match' => 'any',
                'conditions' => [
                    ['type' => 'campaign', 'operator' => 'opened', 'settings' => ['within_days' => 14]],
                ],
            ], ['yes' => 'n8', 'no' => 'n9']),
            $this->node('n8', AutomationNodeType::ActionAddTag, ['tag' => 'sales-ready'], ['next' => 'n10']),
            $this->node('n9', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.lead_nurture.email3_subject'),
                'content_html' => '<p>'.__('automations.blueprints.lead_nurture.email3_body').'</p>',
            ], ['next' => 'n10']),
            $this->node('n10', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /**
     * Anniversary (F-2.4.1-004) — the same date engine as Birthday
     * (F-2.4.2-013) but on a signup/renewal date field, with a negative
     * offset so the heads-up lands a week early and the celebration lands on
     * the day itself.
     *
     * @return array{nodes: list<array<string, mixed>>}
     */
    private function anniversary(): array
    {
        return ['nodes' => [
            $this->node('n1', AutomationNodeType::TriggerDate, [
                'list_field_id' => null,
                'offset_days' => -7,
                'annual' => true,
            ], ['next' => 'n2']),
            $this->node('n2', AutomationNodeType::LogicFrequencyGuard, [
                'quiet_enabled' => true, 'quiet_start' => '21:00', 'quiet_end' => '09:00',
                'cap_enabled' => false,
            ], ['next' => 'n3']),
            $this->node('n3', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.anniversary.email1_subject'),
                'content_html' => '<p>'.__('automations.blueprints.anniversary.email1_body').'</p>',
            ], ['next' => 'n4']),
            $this->node('n4', AutomationNodeType::LogicDelay, ['mode' => 'duration', 'value' => 7, 'unit' => 'days'], ['next' => 'n5']),
            $this->node('n5', AutomationNodeType::ActionSendEmail, [
                'mode' => 'inline',
                'subject' => __('automations.blueprints.anniversary.email2_subject'),
                'content_html' => '<p>'.__('automations.blueprints.anniversary.email2_body').'</p>',
            ], ['next' => 'n6']),
            $this->node('n6', AutomationNodeType::ActionAddTag, ['tag' => 'anniversary'], ['next' => 'n7']),
            $this->node('n7', AutomationNodeType::LogicExit, [], []),
        ]];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $edges
     * @return array<string, mixed>
     */
    private function node(string $uid, AutomationNodeType $type, array $config, array $edges): array
    {
        return [
            'uid' => $uid,
            'type' => $type->value,
            'label' => null,
            'config' => $config,
            'edges' => $edges,
        ];
    }
}
