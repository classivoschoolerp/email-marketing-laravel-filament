<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationStatus;
use App\Models\Automation;
use App\Models\ListSegment;
use App\Models\Subscriber;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Support\Collection;

/**
 * Matches recorded automation events (AutomationEvents) against active
 * automations' trigger configs (spec §2.4.2 trigger rows). Null/empty
 * config keys mean "any" (any list, any tag, any campaign).
 */
class TriggerMatcher
{
    public function __construct(private readonly SegmentQueryBuilder $segments) {}

    /** @return Collection<int, Automation> */
    public function automationsFor(int $customerId, string $event): Collection
    {
        $types = array_map(
            fn (AutomationNodeType $type): string => $type->value,
            AutomationEvents::triggerTypesFor($event),
        );

        if ($types === []) {
            return new Collection;
        }

        return Automation::withoutTenancy()
            ->where('customer_id', $customerId)
            ->where('status', AutomationStatus::Active->value)
            ->whereIn('trigger_type', $types)
            ->get();
    }

    /** @param array<string, mixed> $context */
    public function matches(Automation $automation, string $event, Subscriber $subscriber, array $context): bool
    {
        $config = (array) ($automation->trigger_config ?? []);

        return match ($automation->triggerType()) {
            AutomationNodeType::TriggerSubscribed => $this->listMatches($config, $subscriber)
                && $this->segmentMatches($config, $subscriber),
            AutomationNodeType::TriggerUnsubscribed => $this->listMatches($config, $subscriber),
            // WP-31: the §2.7 module raises form_submitted with a form_id, so
            // the trigger can now name ONE form. An empty form_id still means
            // "any form", which keeps the WP-21 form-sourced-subscription
            // approximation working for lists that use the §2.2.11 embed.
            AutomationNodeType::TriggerFormSubmitted => $this->listMatches($config, $subscriber)
                && $this->idMatches($config, $context, 'form_id'),
            AutomationNodeType::TriggerTagAdded,
            AutomationNodeType::TriggerTagRemoved => $this->tagMatches($config, $context),
            AutomationNodeType::TriggerFieldChanged => $this->fieldMatches($config, $context),
            AutomationNodeType::TriggerCampaignOpened => $this->campaignMatches($config, $context),
            AutomationNodeType::TriggerLinkClicked => $this->campaignMatches($config, $context)
                && $this->urlMatches($config, $context),
            // WP-31: same story for §2.8 surveys (F-2.4.2-012).
            AutomationNodeType::TriggerSurveyCompleted => $this->idMatches($config, $context, 'survey_id'),
            // WP-44 §2.13 (F-2.4.2-017).
            AutomationNodeType::TriggerDealStageChanged => $this->idMatches($config, $context, 'pipeline_id')
                && $this->idMatches($config, $context, 'stage_id'),
            AutomationNodeType::TriggerLeadScore => $this->leadScoreMatches($config, $context),
            // WP-45 §2.12 (F-2.4.2-015 / F-2.4.2-016). Every key is optional
            // and 0/'' means "any", the convention the whole matcher uses.
            AutomationNodeType::TriggerOrderPlaced,
            AutomationNodeType::TriggerOrderShipped => $this->storeMatches($config, $context)
                && $this->minTotalMatches($config, $context)
                && $this->listContains($config, $context, 'category', 'categories')
                && $this->listContains($config, $context, 'product_external_id', 'product_external_ids'),
            AutomationNodeType::TriggerCartAbandoned => $this->storeMatches($config, $context)
                && $this->minTotalMatches($config, $context)
                && $this->listContains($config, $context, 'product_external_id', 'product_external_ids'),
            AutomationNodeType::TriggerProductViewed,
            AutomationNodeType::TriggerBackInStock => $this->storeMatches($config, $context)
                && $this->valueMatches($config, $context, 'product_external_id')
                && $this->valueMatches($config, $context, 'category'),
            AutomationNodeType::TriggerPageVisited => $this->storeMatches($config, $context)
                && $this->pathMatches($config, $context),
            default => false,
        };
    }

    /**
     * WP-45: "this store or any". A trigger left on "any store" follows the
     * customer to a second shop without being re-edited, which is what a
     * multi-store merchant expects.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function storeMatches(array $config, array $context): bool
    {
        return $this->idMatches($config, $context, 'store_connection_id');
    }

    /**
     * WP-45: a minimum order/cart value in MINOR UNITS (D-54 money rule). 0 or
     * absent means "any value" — never a float comparison.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function minTotalMatches(array $config, array $context): bool
    {
        $minimum = (int) ($config['min_total'] ?? 0);

        return $minimum <= 0 || (int) ($context['total'] ?? 0) >= $minimum;
    }

    /**
     * WP-45: an exact, case-insensitive scalar filter; '' means "any".
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function valueMatches(array $config, array $context, string $key): bool
    {
        $wanted = mb_strtolower(trim((string) ($config[$key] ?? '')));

        return $wanted === '' || $wanted === mb_strtolower(trim((string) ($context[$key] ?? '')));
    }

    /**
     * WP-45: does the event's LIST of values contain the configured one? An
     * order carries many categories and many products, so "ordered anything
     * from Shoes" is a containment test, not an equality test.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function listContains(array $config, array $context, string $configKey, string $contextKey): bool
    {
        $wanted = mb_strtolower(trim((string) ($config[$configKey] ?? '')));

        if ($wanted === '') {
            return true;
        }

        foreach ((array) ($context[$contextKey] ?? []) as $value) {
            if (mb_strtolower(trim((string) $value)) === $wanted) {
                return true;
            }
        }

        return false;
    }

    /**
     * WP-45 (F-2.4.2-016): substring match on the visited PATH. Empty means
     * "any page", which is how a generic site-visit journey is configured.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function pathMatches(array $config, array $context): bool
    {
        $wanted = trim((string) ($config['path'] ?? ''));

        return $wanted === '' || str_contains((string) ($context['path'] ?? ''), $wanted);
    }

    /**
     * F-2.4.2-017 "lead score threshold". The producer (LeadScorer) reports
     * both the old and the new value, so this can fire on the CROSSING rather
     * than on the state: a contact who sits at 80 for a month enters the
     * automation once, not once per nightly recompute.
     *
     * Direction is configurable — `above` (default) fires on the way up,
     * `below` on the way down, which is what a re-engagement workflow needs.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function leadScoreMatches(array $config, array $context): bool
    {
        $threshold = (int) ($config['threshold'] ?? 0);

        // 0/absent = "any change", the convention every other key here uses.
        // A threshold of 0 could not mean anything else: the score is clamped
        // to 0..100 and every contact starts at 0.
        if ($threshold <= 0) {
            return true;
        }

        $score = (int) ($context['score'] ?? 0);
        $previous = (int) ($context['previous_score'] ?? 0);

        return match ((string) ($config['direction'] ?? 'above')) {
            'below' => $previous >= $threshold && $score < $threshold,
            default => $previous < $threshold && $score >= $threshold,
        };
    }

    /**
     * Generic "this id or any" filter. An unset/zero config value matches
     * everything, which is the convention every other trigger key uses.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function idMatches(array $config, array $context, string $key): bool
    {
        $wanted = (int) ($config[$key] ?? 0);

        return $wanted <= 0 || $wanted === (int) ($context[$key] ?? 0);
    }

    /** @param array<string, mixed> $config */
    private function listMatches(array $config, Subscriber $subscriber): bool
    {
        $listId = (int) ($config['list_id'] ?? 0);

        return $listId <= 0 || (int) $subscriber->list_id === $listId;
    }

    /** @param array<string, mixed> $config */
    private function segmentMatches(array $config, Subscriber $subscriber): bool
    {
        $segmentId = (int) ($config['segment_id'] ?? 0);

        if ($segmentId <= 0) {
            return true;
        }

        $segment = ListSegment::withoutTenancy()->find($segmentId);

        return $segment !== null && $this->segments
            ->subscribers($segment)
            ->withoutGlobalScopes()
            ->whereKey($subscriber->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function tagMatches(array $config, array $context): bool
    {
        $wanted = mb_strtolower(trim((string) ($config['tag'] ?? '')));

        return $wanted === '' || $wanted === mb_strtolower(trim((string) ($context['tag'] ?? '')));
    }

    /**
     * F-2.4.2-008 — custom field changed / matches value. No operator
     * configured = any change; otherwise the new value must match.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function fieldMatches(array $config, array $context): bool
    {
        $fieldId = (int) ($config['list_field_id'] ?? 0);

        if ($fieldId > 0 && $fieldId !== (int) ($context['list_field_id'] ?? 0)) {
            return false;
        }

        $operator = (string) ($config['operator'] ?? '');

        if ($operator === '') {
            return true;
        }

        $value = mb_strtolower(trim((string) ($context['value'] ?? '')));
        $expected = mb_strtolower(trim((string) ($config['value'] ?? '')));

        return match ($operator) {
            'is' => $value === $expected,
            'is_not' => $value !== $expected,
            'contains' => $expected !== '' && str_contains($value, $expected),
            'not_empty' => $value !== '',
            'is_empty' => $value === '',
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function campaignMatches(array $config, array $context): bool
    {
        $campaignId = (int) ($config['campaign_id'] ?? 0);

        return $campaignId <= 0 || $campaignId === (int) ($context['campaign_id'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function urlMatches(array $config, array $context): bool
    {
        $wanted = trim((string) ($config['url'] ?? ''));

        return $wanted === '' || str_contains((string) ($context['url'] ?? ''), $wanted);
    }

    /**
     * Does a wait-parked node's filter accept this event? Used for runs
     * parked on wait-for-event / delay-until-event nodes.
     *
     * @param  array<string, mixed>  $config  node config
     * @param  array<string, mixed>  $context
     */
    public function waitFilterAccepts(string $event, array $config, array $context): bool
    {
        return match ($event) {
            AutomationEvents::TAG_ADDED, AutomationEvents::TAG_REMOVED => $this->tagMatches($config, $context),
            AutomationEvents::CAMPAIGN_OPENED => $this->campaignMatches($config, $context),
            AutomationEvents::LINK_CLICKED => $this->campaignMatches($config, $context) && $this->urlMatches($config, $context),
            AutomationEvents::FIELD_CHANGED => $this->fieldMatches($config, $context),
            // WP-44 §2.13: a wait node may park on a CRM event too.
            AutomationEvents::DEAL_STAGE_CHANGED => $this->idMatches($config, $context, 'pipeline_id')
                && $this->idMatches($config, $context, 'stage_id'),
            AutomationEvents::LEAD_SCORE_THRESHOLD => $this->leadScoreMatches($config, $context),
            // WP-45 §2.12: a post-purchase journey parks on the shipment and a
            // browse-abandonment journey parks on the order, so both need the
            // same filters the triggers use.
            AutomationEvents::ORDER_PLACED,
            AutomationEvents::ORDER_SHIPPED => $this->storeMatches($config, $context)
                && $this->minTotalMatches($config, $context),
            AutomationEvents::CART_ABANDONED => $this->storeMatches($config, $context)
                && $this->minTotalMatches($config, $context),
            AutomationEvents::PRODUCT_VIEWED,
            AutomationEvents::BACK_IN_STOCK => $this->storeMatches($config, $context)
                && $this->valueMatches($config, $context, 'product_external_id'),
            AutomationEvents::PAGE_VISITED => $this->storeMatches($config, $context)
                && $this->pathMatches($config, $context),
            default => true,
        };
    }
}
