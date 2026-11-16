<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\AutomationNodeType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStatus;
use App\Jobs\Automations\ProcessAutomationEventJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Subscriber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Entry point for the event-driven trigger seam (WP-21). Model hooks call
 * record(); when the customer has an active automation whose trigger the
 * event feeds — or a run parked waiting on it — a ProcessAutomationEventJob
 * is queued on Queues::AUTOMATION. The pre-checks are two indexed EXISTS
 * queries (60s-cached for the high-volume campaign open/click hooks) so
 * bulk imports don't pay a queue-job per row when no automation cares.
 */
class AutomationEventDispatcher
{
    /**
     * Events currently muted, by name → nesting depth (WP-31).
     *
     * A real producer sometimes has to run a mutation that a model hook would
     * ALSO report, less precisely. The §2.7 form module is exactly that case:
     * creating the subscriber makes {@see AutomationHooks} raise
     * `form_submitted` from `source = form` (its documented WP-21
     * approximation, which carries no form id), and the form service then
     * raises the real event with `form_id` in the context. Muting the hook
     * for the duration of the write keeps it at ONE event — the precise one —
     * so a trigger filtered on a specific form still behaves.
     *
     * Static because the dispatcher is resolved fresh on every call site.
     *
     * @var array<string, int>
     */
    private static array $muted = [];

    /**
     * Run $callback with $event muted. Always paired with a finally, so an
     * exception can never leave the bus deaf.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function mute(string $event, callable $callback): mixed
    {
        self::$muted[$event] = (self::$muted[$event] ?? 0) + 1;

        try {
            return $callback();
        } finally {
            if (--self::$muted[$event] <= 0) {
                unset(self::$muted[$event]);
            }
        }
    }

    public static function isMuted(string $event): bool
    {
        return isset(self::$muted[$event]);
    }

    public function record(string $event, Subscriber $subscriber, array $context = []): void
    {
        try {
            if (self::isMuted($event)) {
                return;
            }

            if (! Schema::hasTable('automations')) {
                return; // installer safety — hooks may fire pre-migration
            }

            if (! $this->anyoneCares((int) $subscriber->customer_id, $event, (int) $subscriber->id)) {
                return;
            }

            ProcessAutomationEventJob::dispatch(
                (int) $subscriber->customer_id,
                $event,
                (int) $subscriber->id,
                $context,
            );
        } catch (Throwable) {
            // Trigger recording must never break the mutating operation.
        }
    }

    /** Cheap global switch for the high-volume open/click tracking hooks. */
    public function campaignEventsEnabled(): bool
    {
        try {
            if (! Schema::hasTable('automations')) {
                return false;
            }

            return (bool) Cache::remember('automations:campaign-events-enabled', 60, function (): bool {
                $triggerTypes = [
                    AutomationNodeType::TriggerCampaignOpened->value,
                    AutomationNodeType::TriggerLinkClicked->value,
                ];

                return Automation::withoutTenancy()
                    ->where('status', AutomationStatus::Active->value)
                    ->whereIn('trigger_type', $triggerTypes)
                    ->exists()
                    || AutomationRun::withoutTenancy()
                        ->where('status', AutomationRunStatus::Active->value)
                        ->whereIn('waiting_for', [AutomationEvents::CAMPAIGN_OPENED, AutomationEvents::LINK_CLICKED])
                        ->exists();
            });
        } catch (Throwable) {
            return false;
        }
    }

    private function anyoneCares(int $customerId, string $event, int $subscriberId): bool
    {
        $types = array_map(
            fn (AutomationNodeType $type): string => $type->value,
            AutomationEvents::triggerTypesFor($event),
        );

        $hasTrigger = $types !== [] && Automation::withoutTenancy()
            ->where('customer_id', $customerId)
            ->where('status', AutomationStatus::Active->value)
            ->whereIn('trigger_type', $types)
            ->exists();

        if ($hasTrigger) {
            return true;
        }

        return AutomationRun::withoutTenancy()
            ->where('subscriber_id', $subscriberId)
            ->where('status', AutomationRunStatus::Active->value)
            ->where('waiting_for', $event)
            ->exists();
    }
}
