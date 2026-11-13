<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\DeliveryServerStatus;
use App\Enums\QuotaExceededAction;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerGroup;
use App\Models\Scopes\CustomerScope;
use App\Services\Sending\DeliveryServerAssignments;
use App\Services\Sending\DeliveryServerQuota;
use App\Services\Warmup\WarmupQuota;
use App\Support\Tenancy\CurrentCustomer;
use Random\Randomizer;

/**
 * Multi-server load balancing (spec §2.9.2 F-2.9.2-022): weighted-random
 * choice by `probability` among the servers a customer may use.
 *
 * Eligibility filters, in order (each documented in its own row test):
 *  1. visible to the customer: own rows + group-assigned system rows
 *  2. pool restriction (§3.13.1 F-3.13.1-008, WP-33): an explicitly passed
 *     group, otherwise the customer's single enabled default pool
 *  3. status active (hourly-quota-reached rows are re-checked and recover
 *     automatically once the window resets — F-2.9.2-042)
 *  4. list/campaign assignment (§2.9.2 F-2.9.2-038, WP-42): a server narrowed
 *     to specific lists/campaigns is skipped for anything else. Checked
 *     BEFORE quotas so a restricted PAUSE server can never abort a pick for a
 *     campaign it was never assigned to (D-50 untouched).
 *  5. driver type allowed for this customer (D-21 whitelist chain)
 *  6. quota headroom on all three windows (ServerUsage; 0 = unlimited D-23)
 *  7. warmup cap (§2.9.3 F-2.9.3-009, WP-33): a server that has consumed
 *     today's warmup allowance is skipped
 *
 * Pause-vs-next (F-2.9.2-030): a quota-exhausted server with action PAUSE
 * demands waiting — the pick aborts with shouldPause + the seconds until the
 * hourly window resets (its traffic must not silently fail over). Exhausted
 * servers with MOVE-TO-NEXT are simply skipped. Candidates are evaluated in
 * id order, so the outcome is deterministic.
 *
 * Warmup deferral (§2.9.3 F-2.9.3-010): when every remaining candidate is
 * warmup-capped the pick also aborts with shouldPause, this time until the
 * warmup day rolls over. That reuses the D-61 deferral path in
 * SendToSubscriberJob — the recipient is retried by a later send wave and is
 * never marked failed, and no idempotency key is consumed.
 *
 * The chosen server's warmup slot is RESERVED before it is returned, so two
 * workers can never both win the last email of a warmup day.
 *
 * Randomness is injectable (Random\Randomizer) for deterministic-seed tests.
 */
class ServerPicker
{
    private readonly Randomizer $randomizer;

    private readonly WarmupQuota $warmup;

    private readonly DeliveryServerAssignments $assignments;

    public function __construct(
        private readonly ServerUsage $usage,
        private readonly DeliveryServerQuota $quota,
        ?Randomizer $randomizer = null,
        ?WarmupQuota $warmup = null,
        ?DeliveryServerAssignments $assignments = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer;
        $this->warmup = $warmup ?? app(WarmupQuota::class);
        $this->assignments = $assignments ?? app(DeliveryServerAssignments::class);
    }

    /**
     * @param  list<int>|null  $onlyServerIds  WP-13: restrict to the campaign's
     *                                         delivery_server_ids selection (null/[] = no restriction)
     * @param  int|null  $groupId  WP-33: restrict to this delivery server pool
     *                             (null = the customer's default pool, if any)
     * @param  int|null  $campaignId  WP-42 F-2.9.2-038: the campaign being sent,
     *                                matched against per-server assignments
     * @param  list<int>|null  $listIds  WP-42 F-2.9.2-038: that campaign's target
     *                                   lists, matched against per-server assignments
     */
    public function pickFor(
        Customer $customer,
        int $amount = 1,
        ?array $onlyServerIds = null,
        ?int $groupId = null,
        ?int $campaignId = null,
        ?array $listIds = null,
    ): PickResult {
        $eligible = [];
        $restriction = array_map('intval', $onlyServerIds ?? []);
        $pool = $this->poolRestriction($customer, $groupId);
        $warmupBlocked = false;

        $candidates = $this->candidatesFor($customer);
        $assignments = $this->assignments->mapFor(
            array_map(static fn (DeliveryServer $s): int => (int) $s->id, $candidates),
        );
        $targetLists = array_map('intval', $listIds ?? []);

        foreach ($candidates as $server) {
            if ($restriction !== [] && ! in_array((int) $server->id, $restriction, true)) {
                continue;
            }

            if ($pool !== null && ! in_array((int) $server->id, $pool, true)) {
                continue;
            }

            // F-2.9.2-038: assigned-to-specific-lists/campaigns-only.
            if (! DeliveryServerAssignments::permits(
                $assignments[(int) $server->id] ?? DeliveryServerAssignments::NONE,
                $campaignId,
                $targetLists,
            )) {
                continue;
            }

            $this->refreshQuotaStatus($server);

            if (! $this->quota->typeAllowed($customer, $server->type)) {
                continue;
            }

            if ($server->status !== DeliveryServerStatus::Active
                && $server->status !== DeliveryServerStatus::HourlyQuotaReached) {
                continue;
            }

            if ($this->usage->wouldExceedAnyQuota($server, $amount)) {
                if ($server->quota_exceeded_action === QuotaExceededAction::Pause) {
                    return PickResult::pause($this->usage->secondsUntilHourlyReset());
                }

                continue; // MOVE-TO-NEXT: let the rest of the pool absorb it
            }

            if ($this->warmupCapped($server, $amount)) {
                $warmupBlocked = true;

                continue;
            }

            $eligible[] = $server;
        }

        $chosen = $this->chooseAndReserve($eligible, $amount, $warmupBlocked);

        if ($chosen === null && $warmupBlocked) {
            // Every candidate is inside its warmup ceiling: defer to the next
            // warmup day instead of failing the recipient (F-2.9.3-010).
            return PickResult::pause($this->warmup->secondsUntilNextDay());
        }

        return new PickResult($chosen);
    }

    /**
     * Servers visible to this customer, in deterministic id order.
     *
     * @return list<DeliveryServer>
     */
    public function candidatesFor(Customer $customer): array
    {
        return app(CurrentCustomer::class)->runAs(
            $customer,
            fn () => DeliveryServer::query()
                ->browsable()
                ->whereIn('status', [
                    DeliveryServerStatus::Active->value,
                    DeliveryServerStatus::HourlyQuotaReached->value,
                ])
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }

    /**
     * Keep the status honest around the hourly window (F-2.9.2-042):
     * active + exhausted → hourly-quota-reached; reached + fresh window →
     * active again.
     */
    public function refreshQuotaStatus(DeliveryServer $server): void
    {
        $exceeded = $this->usage->wouldExceedHourly($server);

        if ($server->status === DeliveryServerStatus::Active && $exceeded) {
            $server->status = DeliveryServerStatus::HourlyQuotaReached;
            $server->saveQuietly();
        } elseif ($server->status === DeliveryServerStatus::HourlyQuotaReached && ! $exceeded) {
            $server->status = DeliveryServerStatus::Active;
            $server->saveQuietly();
        }
    }

    /**
     * Warmup enforcement (spec §2.9.3 F-2.9.3-009, WP-33): a server whose
     * warmup allowance for today is consumed — counting both landed sends and
     * capacity reserved by concurrent workers — is not eligible.
     */
    protected function warmupCapped(DeliveryServer $server, int $amount): bool
    {
        return $this->warmup->wouldExceed($server, $amount);
    }

    /**
     * Member server ids of the pool this pick is confined to, or null when
     * the whole visible fleet is allowed (F-3.13.1-008).
     *
     * @return list<int>|null
     */
    protected function poolRestriction(Customer $customer, ?int $groupId): ?array
    {
        $query = DeliveryServerGroup::query()->withoutGlobalScope(CustomerScope::class);

        if ($groupId !== null) {
            $group = (clone $query)->whereKey($groupId)->first();
        } else {
            $group = (clone $query)
                ->where('customer_id', $customer->id)
                ->where('is_default', true)
                ->where('enabled', true)
                ->orderBy('id')
                ->first();
        }

        if ($group === null || ! $group->enabled) {
            return null;
        }

        return $group->memberIds();
    }

    /**
     * Weighted choice that also claims the winner's warmup slot; a server
     * that loses the race is dropped and the draw is repeated.
     *
     * @param  list<DeliveryServer>  $servers
     */
    private function chooseAndReserve(array $servers, int $amount, bool &$warmupBlocked): ?DeliveryServer
    {
        while ($servers !== []) {
            $chosen = $this->weightedChoice($servers);

            if ($chosen === null) {
                return null;
            }

            if ($this->warmup->reserve($chosen, $amount)) {
                return $chosen;
            }

            // Lost the last warmup slot to a concurrent worker.
            $warmupBlocked = true;
            $servers = array_values(array_filter(
                $servers,
                static fn (DeliveryServer $s): bool => (int) $s->id !== (int) $chosen->id,
            ));
        }

        return null;
    }

    /**
     * Weighted random by probability (F-2.9.2-028). All-zero weights fall
     * back to a uniform choice so a misconfigured pool never deadlocks.
     *
     * @param  list<DeliveryServer>  $servers
     */
    public function weightedChoice(array $servers): ?DeliveryServer
    {
        if ($servers === []) {
            return null;
        }

        $weights = array_map(
            static fn (DeliveryServer $s): int => max(0, (int) $s->probability),
            $servers,
        );

        $total = array_sum($weights);

        if ($total === 0) {
            $weights = array_fill(0, count($servers), 1);
            $total = count($servers);
        }

        $roll = $this->randomizer->getInt(1, $total);

        foreach ($servers as $i => $server) {
            $roll -= $weights[$i];

            if ($roll <= 0) {
                return $server;
            }
        }

        return $servers[array_key_last($servers)];
    }
}
