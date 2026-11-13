<?php

declare(strict_types=1);

namespace App\Services\Sending;

use App\Models\Campaign;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerAssignment;
use App\Models\EmailList;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * F-2.9.2-038 "Assign to specific lists/campaigns only" (WP-42).
 *
 * A delivery server may be narrowed to an explicit set of the owning tenant's
 * lists and/or campaigns. Convention (same as sub_user_list_access,
 * §2.15.4 F-2.15.4-003): an EMPTY set is NO restriction.
 *
 * Match semantics when the set is not empty — the server is usable when
 * EITHER dimension matches:
 *   - the campaign being sent is one of the assigned campaigns, OR
 *   - any of that campaign's target lists is one of the assigned lists.
 * Otherwise the server is skipped by ServerPicker before quotas are even
 * consulted, so the D-50 pause-vs-next semantics of OTHER servers are
 * untouched (a restricted, quota-exhausted PAUSE server must not abort a pick
 * for a campaign it was never assigned to).
 *
 * Tenancy: {@see sync()} is the ONLY writer and it drops/refuses ids that are
 * not owned by the server's own customer, so a cross-tenant list or campaign
 * can never be attached. System servers (customer_id NULL) have no owning
 * tenant and therefore accept no assignments at all.
 */
class DeliveryServerAssignments
{
    /** Empty restriction (unrestricted server). */
    public const NONE = ['list' => [], 'campaign' => []];

    /**
     * Assigned resource ids for one server.
     *
     * @return array{list: list<int>, campaign: list<int>}
     */
    public function forServer(DeliveryServer $server): array
    {
        return $this->mapFor([(int) $server->id])[(int) $server->id] ?? self::NONE;
    }

    /**
     * Assigned resource ids for many servers in ONE query (the send path picks
     * from the whole visible fleet on every recipient).
     *
     * @param  list<int>  $serverIds
     * @return array<int, array{list: list<int>, campaign: list<int>}>
     */
    public function mapFor(array $serverIds): array
    {
        if ($serverIds === []) {
            return [];
        }

        $map = [];

        DeliveryServerAssignment::query()
            ->whereIn('delivery_server_id', $serverIds)
            ->get(['delivery_server_id', 'resource_type', 'resource_id'])
            ->each(function (DeliveryServerAssignment $row) use (&$map): void {
                $serverId = (int) $row->delivery_server_id;
                $type = (string) $row->resource_type;

                if (! in_array($type, DeliveryServerAssignment::RESOURCE_TYPES, true)) {
                    return;
                }

                $map[$serverId] ??= self::NONE;
                $map[$serverId][$type][] = (int) $row->resource_id;
            });

        return $map;
    }

    /**
     * Does this restriction permit a send of the given campaign / target lists?
     *
     * @param  array{list: list<int>, campaign: list<int>}  $restriction
     * @param  list<int>  $listIds
     */
    public static function permits(array $restriction, ?int $campaignId, array $listIds = []): bool
    {
        $lists = $restriction['list'] ?? [];
        $campaigns = $restriction['campaign'] ?? [];

        if ($lists === [] && $campaigns === []) {
            return true; // no rows = unrestricted
        }

        if ($campaignId !== null && in_array($campaignId, $campaigns, true)) {
            return true;
        }

        foreach ($listIds as $listId) {
            if (in_array((int) $listId, $lists, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convenience single-server check (model-level mirror of the picker gate).
     *
     * @param  list<int>  $listIds
     */
    public function allows(DeliveryServer $server, ?int $campaignId = null, array $listIds = []): bool
    {
        return self::permits($this->forServer($server), $campaignId, $listIds);
    }

    /** Is this server narrowed at all? */
    public function isRestricted(DeliveryServer $server): bool
    {
        $restriction = $this->forServer($server);

        return $restriction['list'] !== [] || $restriction['campaign'] !== [];
    }

    /**
     * Replace a server's assignments. Ids not owned by the server's customer
     * are refused outright — silently dropping them would be a security hole
     * that reads like a UI glitch.
     *
     * @param  list<int|string>  $listIds
     * @param  list<int|string>  $campaignIds
     *
     * @throws ValidationException
     */
    public function sync(DeliveryServer $server, array $listIds, array $campaignIds): void
    {
        [
            'list' => $lists,
            'campaign' => $campaigns,
        ] = $this->validate($server->customer_id === null ? null : (int) $server->customer_id, $listIds, $campaignIds);

        $rows = [];
        $now = now();

        foreach ($lists as $id) {
            $rows[] = [
                'delivery_server_id' => (int) $server->id,
                'resource_type' => DeliveryServerAssignment::RESOURCE_LIST,
                'resource_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($campaigns as $id) {
            $rows[] = [
                'delivery_server_id' => (int) $server->id,
                'resource_type' => DeliveryServerAssignment::RESOURCE_CAMPAIGN,
                'resource_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Replace atomically: a half-applied sync would silently WIDEN the
        // server's reach, which is the wrong way for a restriction to fail.
        DB::transaction(function () use ($server, $rows): void {
            DeliveryServerAssignment::query()
                ->where('delivery_server_id', $server->id)
                ->delete();

            if ($rows !== []) {
                DeliveryServerAssignment::query()->insert($rows);
            }
        });
    }

    /**
     * Cross-tenant guard, usable BEFORE a server row exists (the Filament
     * create page validates here so a forged payload never produces a record).
     *
     * @param  list<int|string>  $listIds
     * @param  list<int|string>  $campaignIds
     * @return array{list: list<int>, campaign: list<int>}
     *
     * @throws ValidationException
     */
    public function validate(?int $customerId, array $listIds, array $campaignIds): array
    {
        return [
            'list' => $this->assertOwned($customerId, EmailList::class, $listIds, 'assigned_list_ids'),
            'campaign' => $this->assertOwned($customerId, Campaign::class, $campaignIds, 'assigned_campaign_ids'),
        ];
    }

    /**
     * Every supplied id must exist AND belong to the given customer. A system
     * server (customer_id NULL) owns nothing, so any id is cross-tenant for it.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  list<int|string>  $ids
     * @return list<int>
     *
     * @throws ValidationException
     */
    protected function assertOwned(?int $customerId, string $model, array $ids, string $attribute): array
    {
        $wanted = array_values(array_unique(array_map('intval', array_filter(
            $ids,
            static fn ($id): bool => $id !== null && $id !== '',
        ))));

        if ($wanted === []) {
            return [];
        }

        $owned = $customerId === null ? [] : $model::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $wanted)
            ->where('customer_id', $customerId)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (count($owned) !== count($wanted)) {
            throw ValidationException::withMessages([
                $attribute => __('sending.errors.assignment_foreign'),
            ]);
        }

        sort($owned);

        return array_values($owned);
    }
}
