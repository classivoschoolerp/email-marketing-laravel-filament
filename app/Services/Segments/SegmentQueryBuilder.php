<?php

declare(strict_types=1);

namespace App\Services\Segments;

use App\Enums\AutomationRunStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentMatchType;
use App\Enums\SegmentOperator;
use App\Models\Automation;
use App\Models\Campaign;
use App\Models\CampaignTrackUrl;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\ListSegment;
use App\Models\ListSegmentCondition;
use App\Models\ListSegmentSnapshotMember;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Models\Subscriber;
use App\Services\Subscribers\EngagementQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates segment conditions into a Subscriber query (spec §2.2.10).
 * Segments are dynamic: membership is computed at read time, never stored
 * (F-2.2.10-012) — campaign targeting later calls subscribers() at send time.
 * The one exception is a STATIC segment (F-2.2.10-013): once frozen it
 * resolves from its snapshot rows instead, which happens inside subscribers()
 * so every consumer (campaign/SMS targeting, exports, automations) inherits it.
 *
 * Campaign conditions (F-2.2.10-003) run against the WP-09/13 tracking
 * tables (campaign_track_opens / campaign_track_clicks / campaign_track_urls),
 * which are keyed by campaign_uid + subscriber_uid; see
 * applyCampaignCondition().
 *
 * WP-42 adds three more families to the SAME applyCondition() switch — there
 * is deliberately no second evaluation path:
 *   engagement (F-2.2.10-006) — opened at least N times / no opens in N days;
 *   geo        (F-2.2.10-007) — country / region / city / timezone;
 *   automation (F-2.2.10-009) — in automation X / completed automation X;
 * plus nested condition groups (F-2.2.10-011): AND of ORs, see build().
 */
class SegmentQueryBuilder
{
    public function __construct(private readonly EngagementQuery $engagement) {}

    /**
     * Membership query for a saved segment: the frozen snapshot when the
     * segment is static (F-2.2.10-013), otherwise the dynamic evaluation of
     * its conditions (F-2.2.10-012).
     */
    public function subscribers(ListSegment $segment): Builder
    {
        if ($segment->isStatic()) {
            return $this->snapshotSubscribers($segment);
        }

        return $this->dynamicSubscribers($segment);
    }

    /**
     * Condition-based membership, ignoring any snapshot. This is what the
     * freeze job materialises and what the segment editor previews.
     */
    public function dynamicSubscribers(ListSegment $segment): Builder
    {
        /** @var EmailList $list */
        $list = EmailList::withoutTenancy()->findOrFail($segment->list_id);

        return $this->build($list, $segment->match_type, $this->conditionArrays($segment));
    }

    /**
     * Frozen membership (F-2.2.10-013). A static segment whose snapshot was
     * never taken matches NOTHING — falling back to the live set would defeat
     * the freeze and mail people the customer did not capture (same spirit as
     * D-90).
     */
    private function snapshotSubscribers(ListSegment $segment): Builder
    {
        /** @var EmailList $list */
        $list = EmailList::withoutTenancy()->findOrFail($segment->list_id);

        $query = Subscriber::query()->where('list_id', $list->id);

        if (! $segment->hasSnapshot()) {
            return $query->whereRaw('1 = 0');
        }

        $table = ListSegmentSnapshotMember::TABLE;

        return $query->whereExists(function (QueryBuilder $sub) use ($table, $segment, $list): void {
            $sub->selectRaw('1')
                ->from($table)
                ->whereColumn($table.'.subscriber_id', 'subscribers.id')
                ->where($table.'.list_segment_id', $segment->id)
                // Re-fence to the owning tenant: a snapshot row can never pull
                // in someone from another customer.
                ->where($table.'.customer_id', $list->customer_id);
        });
    }

    /**
     * The segment's stored conditions as the raw arrays build() consumes.
     *
     * @return list<array<string, mixed>>
     */
    private function conditionArrays(ListSegment $segment): array
    {
        return $segment->conditions()->get()->map(fn (ListSegmentCondition $condition): array => [
            'type' => $condition->type->value,
            'list_field_id' => $condition->list_field_id,
            'field_key' => $condition->field_key,
            'operator' => $condition->operator->value,
            'value' => $condition->value,
            'value2' => $condition->value2,
            'settings' => $condition->settings,
            'group_index' => $condition->group_index,
            'group_match' => $condition->group_match,
        ])->all();
    }

    public function count(ListSegment $segment): int
    {
        return $this->subscribers($segment)->count();
    }

    /**
     * Build a query from raw condition arrays — also used by the live count
     * preview before the segment is saved (F-2.2.10-010).
     *
     * Nested condition groups (F-2.2.10-011): each condition carries an
     * optional `group_index` (default 0) and its group's optional
     * `group_match` (all|any). Groups are combined with the segment's
     * match_type; the conditions inside one group are combined with that
     * group's own operator, defaulting to the segment's match_type.
     *
     * With a single group — every pre-WP-42 segment, since group_index
     * defaults to 0 — this reduces to exactly the previous flat behaviour:
     * one nested WHERE combining the conditions with the segment match_type.
     * `match_type = all` + per-group `any` is the spec's "AND of ORs".
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    public function build(EmailList $list, SegmentMatchType|string $match, array $conditions): Builder
    {
        $match = $match instanceof SegmentMatchType ? $match : SegmentMatchType::from($match);

        $query = Subscriber::query()->where('list_id', $list->id);

        $conditions = array_values(array_filter(
            $conditions,
            fn (array $condition): bool => ($condition['operator'] ?? '') !== '',
        ));

        if ($conditions === []) {
            return $query;
        }

        $groups = self::groupConditions($conditions);

        $query->where(function (Builder $outer) use ($list, $match, $groups): void {
            foreach ($groups as $group) {
                $outer->where(
                    function (Builder $inner) use ($list, $match, $group): void {
                        $groupMatch = self::groupMatch($group, $match);

                        foreach ($group as $condition) {
                            $inner->where(
                                fn (Builder $q) => $this->applyCondition($q, $condition, $list),
                                null,
                                null,
                                $groupMatch === SegmentMatchType::Any ? 'or' : 'and',
                            );
                        }
                    },
                    null,
                    null,
                    $match === SegmentMatchType::Any ? 'or' : 'and',
                );
            }
        });

        return $query;
    }

    /**
     * Split conditions into groups keyed by group_index, preserving both the
     * order the groups first appear in and the order inside each group.
     *
     * @param  list<array<string, mixed>>  $conditions
     * @return list<list<array<string, mixed>>>
     */
    private static function groupConditions(array $conditions): array
    {
        $groups = [];

        foreach ($conditions as $condition) {
            $index = (int) ($condition['group_index'] ?? 0);
            $groups[$index][] = $condition;
        }

        return array_values($groups);
    }

    /**
     * A group's own ALL/ANY operator: the first non-empty `group_match` any of
     * its conditions declares, else the segment's match type.
     *
     * @param  list<array<string, mixed>>  $group
     */
    private static function groupMatch(array $group, SegmentMatchType $fallback): SegmentMatchType
    {
        foreach ($group as $condition) {
            $raw = $condition['group_match'] ?? null;

            if (is_string($raw) && $raw !== '') {
                $parsed = SegmentMatchType::tryFrom($raw);

                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        return $fallback;
    }

    /** @param  list<array<string, mixed>>  $conditions */
    public function countFor(EmailList $list, SegmentMatchType|string $match, array $conditions): int
    {
        return $this->build($list, $match, $conditions)->count();
    }

    /** @param array<string, mixed> $condition */
    private function applyCondition(Builder $query, array $condition, EmailList $list): void
    {
        $type = SegmentConditionType::from((string) ($condition['type'] ?? 'field'));
        $operator = SegmentOperator::from((string) $condition['operator']);
        $value = isset($condition['value']) ? (string) $condition['value'] : null;
        $value2 = isset($condition['value2']) ? (string) $condition['value2'] : null;

        match ($type) {
            SegmentConditionType::Field => $this->applyFieldCondition($query, $condition, $operator, $value, $value2),
            SegmentConditionType::Date => $this->applyDateCondition($query, $condition, $operator, $value, $value2),
            SegmentConditionType::Status => $this->applyStatusCondition($query, $operator, $value),
            SegmentConditionType::Campaign => $this->applyCampaignCondition($query, $condition, $operator, $list),
            SegmentConditionType::Engagement => $this->applyEngagementCondition($query, $condition, $operator, $value, $list),
            SegmentConditionType::Geo => $this->applyGeoCondition($query, $condition, $operator, $value, $list),
            SegmentConditionType::Automation => $this->applyAutomationCondition($query, $condition, $operator, $list),
            SegmentConditionType::Purchase => $this->applyPurchaseCondition($query, $condition, $operator, $value, $list),
        };
    }

    /* ================================================================ *
     *  §2.12 F-2.12-006 — purchase conditions (WP-45)
     * ================================================================ */

    /**
     * Purchase-based segmentation: spend, frequency, category, last order.
     *
     * SOURCE is `store_orders` / `store_order_items` — the tenant's own synced
     * orders, joined to the subscriber the sync resolved by email. NOT
     * `store_customers.total_spent`: a shop's lifetime figure cannot be
     * windowed by date, and "spent over 500 in the last 90 days" is the
     * segment people actually build.
     *
     * MONEY is compared in integer MINOR UNITS on both sides. The form labels
     * the input accordingly, and nothing here ever produces a float.
     *
     * CURRENCY: a spend comparison is only meaningful within one currency, so
     * `settings.currency` narrows it; left empty the sum spans everything the
     * shopper bought, which is right for a single-currency shop (the
     * overwhelming majority) and stated in the field's helper text.
     *
     * STATUS: only orders in {@see StoreOrder::REVENUE_STATUSES} count.
     * A refunded order is not a purchase, and a segment that mails people for
     * money they got back is worse than a segment that misses them.
     *
     * Semantics of the negatives, stated because they are not complements:
     *   `not_ordered_category` = "never ordered from that category", which
     *   INCLUDES people who never ordered at all — that is what a merchant
     *   means by "hasn't tried Shoes yet".
     *
     * @param  array<string, mixed>  $condition
     */
    private function applyPurchaseCondition(
        Builder $query,
        array $condition,
        SegmentOperator $operator,
        ?string $value,
        EmailList $list,
    ): void {
        if (! SegmentConditionType::Purchase->isAvailable()) {
            $query->whereRaw('1 = 0');

            return;
        }

        /** @var array<string, mixed> $settings */
        $settings = is_array($condition['settings'] ?? null) ? $condition['settings'] : [];

        $customerId = (int) $list->customer_id;
        $number = (int) $value;
        $withinDays = (int) ($settings['within_days'] ?? 0);
        $currency = trim((string) ($settings['currency'] ?? ''));
        $connectionId = $this->tenantConnectionId($settings, $customerId, $broken);

        if ($broken) {
            // An unknown or cross-tenant store matches NOTHING in both
            // directions — the D-90 convention.
            $query->whereRaw('1 = 0');

            return;
        }

        $orders = fn (QueryBuilder $sub): QueryBuilder => $this->orderScope(
            $sub,
            $customerId,
            $connectionId,
            $withinDays,
            $currency,
        );

        // EXISTS subqueries select the constant 1; the aggregate variants
        // select their aggregate instead — never both, or SQLite reports
        // "row value misused" on the comparison.
        $exists = fn (QueryBuilder $sub): QueryBuilder => $orders($sub)->selectRaw('1');

        match ($operator) {
            SegmentOperator::SpentAtLeast => $this->whereOrderAggregate($query, $orders, 'coalesce(sum(store_orders.total), 0)', '>=', max(0, $number)),
            SegmentOperator::SpentAtMost => $this->whereOrderAggregate($query, $orders, 'coalesce(sum(store_orders.total), 0)', '<=', max(0, $number)),
            SegmentOperator::OrderedAtLeast => $this->whereOrderAggregate($query, $orders, 'count(*)', '>=', max(1, $number)),
            SegmentOperator::OrderedAtMost => $this->whereOrderAggregate($query, $orders, 'count(*)', '<=', max(0, $number)),
            SegmentOperator::LastOrderWithinDays => $query->whereExists(function (QueryBuilder $sub) use ($exists, $number): void {
                $exists($sub)->where(
                    'store_orders.placed_at',
                    '>=',
                    Carbon::now()->subDays(max(1, $number)),
                );
            }),
            SegmentOperator::LastOrderBeforeDays => $query
                // "Last order longer ago than N days" = they ordered at some
                // point AND have nothing inside the window. Both halves are
                // needed: without the first, everyone who never ordered would
                // match, which is a different segment entirely.
                ->whereExists(fn (QueryBuilder $sub) => $exists($sub))
                ->whereNotExists(function (QueryBuilder $sub) use ($exists, $number): void {
                    $exists($sub)->where(
                        'store_orders.placed_at',
                        '>=',
                        Carbon::now()->subDays(max(1, $number)),
                    );
                }),
            SegmentOperator::NeverOrdered => $query->whereNotExists(fn (QueryBuilder $sub) => $exists($sub)),
            SegmentOperator::OrderedCategory => $query->whereExists(
                fn (QueryBuilder $sub) => $this->itemScope($exists($sub), 'category', (string) $value),
            ),
            SegmentOperator::NotOrderedCategory => $query->whereNotExists(
                fn (QueryBuilder $sub) => $this->itemScope($exists($sub), 'category', (string) $value),
            ),
            SegmentOperator::OrderedProduct => $query->whereExists(
                fn (QueryBuilder $sub) => $this->itemScope($exists($sub), 'product_external_id', (string) $value),
            ),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * The correlated order subquery every purchase operator starts from:
     * this subscriber's own revenue-counting orders, tenant-fenced, optionally
     * narrowed to one store, one currency and a date window.
     */
    private function orderScope(
        QueryBuilder $sub,
        int $customerId,
        ?int $connectionId,
        int $withinDays,
        string $currency,
    ): QueryBuilder {
        // NOTE: no select() here — the caller adds either `1` (EXISTS) or an
        // aggregate (comparison), and adding both breaks SQLite.
        $sub->from('store_orders')
            ->whereColumn('store_orders.subscriber_id', 'subscribers.id')
            ->where('store_orders.customer_id', $customerId)
            ->whereIn('store_orders.status', StoreOrder::REVENUE_STATUSES);

        if ($connectionId !== null) {
            $sub->where('store_orders.store_connection_id', $connectionId);
        }

        if ($currency !== '') {
            $sub->where('store_orders.currency', strtoupper($currency));
        }

        if ($withinDays > 0) {
            $sub->where('store_orders.placed_at', '>=', Carbon::now()->subDays($withinDays));
        }

        return $sub;
    }

    /** Narrow an order subquery to those containing a matching line. */
    private function itemScope(QueryBuilder $sub, string $column, string $value): QueryBuilder
    {
        return $sub->whereExists(function (QueryBuilder $items) use ($column, $value): void {
            $items->selectRaw('1')
                ->from('store_order_items')
                ->whereColumn('store_order_items.store_order_id', 'store_orders.id')
                ->whereRaw('lower('.$column.') = ?', [mb_strtolower(trim($value))]);
        });
    }

    /**
     * A correlated aggregate comparison ("sum(total) >= N", "count(*) >= N").
     *
     * EngagementQuery only answers EXISTS, and {@see whereEventCountAtLeast()}
     * only counts one tracking table, so the money variant lives here — same
     * shape, same tenant fence, same `whereRaw` injection of the compiled
     * subquery with its bindings preserved.
     *
     * @param  callable(QueryBuilder): QueryBuilder  $scope
     */
    private function whereOrderAggregate(
        Builder $query,
        callable $scope,
        string $aggregate,
        string $comparison,
        int $threshold,
    ): void {
        $sub = $scope(DB::query());
        $sub->selectRaw($aggregate);

        $query->whereRaw('('.$sub->toSql().') '.$comparison.' ?', [...$sub->getBindings(), $threshold]);
    }

    /**
     * `settings.store_connection_id` re-fenced to this list's tenant. Null =
     * every store; `$broken` is set when a store was named but is unknown or
     * belongs to somebody else.
     *
     * @param  array<string, mixed>  $settings
     */
    private function tenantConnectionId(array $settings, int $customerId, ?bool &$broken): ?int
    {
        $broken = false;
        $raw = $settings['store_connection_id'] ?? null;

        if ($raw === null || $raw === '' || (int) $raw <= 0) {
            return null;
        }

        $id = StoreConnection::withoutTenancy()
            ->where('customer_id', $customerId)
            ->whereKey((int) $raw)
            ->value('id');

        if ($id === null) {
            $broken = true;

            return null;
        }

        return (int) $id;
    }

    /**
     * Connected stores for the segment condition builder (F-2.12-006) —
     * tenant-scoped.
     *
     * @return array<int, string>
     */
    public function storeOptions(EmailList $list): array
    {
        if (! SegmentConditionType::Purchase->isAvailable()) {
            return [];
        }

        return StoreConnection::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->orderBy('id')
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * Product categories the tenant's synced catalogue actually contains
     * (F-2.12-006). An empty list means "nothing synced yet", which the form
     * says rather than offering a free-text field that can only miss.
     *
     * @return array<string, string>
     */
    public function purchaseCategoryOptions(EmailList $list): array
    {
        if (! SegmentConditionType::Purchase->isAvailable()) {
            return [];
        }

        return StoreProduct::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->limit(200)
            ->pluck('category', 'category')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /** @param array<string, mixed> $condition */
    private function applyFieldCondition(Builder $query, array $condition, SegmentOperator $operator, ?string $value, ?string $value2): void
    {
        $fieldId = $condition['list_field_id'] ?? null;

        if ($fieldId === null || $fieldId === '') {
            // Standard column (F-2.2.10-002): currently the email address.
            $this->applyColumnOperator($query, 'email', $operator, $value, $value2);

            return;
        }

        $field = ListField::withoutTenancy()->find((int) $fieldId);

        if ($field === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $numeric = $field->type->isNumeric();

        match ($operator) {
            SegmentOperator::Is => $this->whereHasValue($query, $field, fn (Builder $q) => $q->where('value', $value)),
            SegmentOperator::IsNot => $this->whereDoesntHaveValue($query, $field, fn (Builder $q) => $q->where('value', $value)),
            SegmentOperator::Contains => $this->whereHasValue($query, $field, fn (Builder $q) => $q->where('value', 'like', '%'.self::escapeLike((string) $value).'%')),
            SegmentOperator::NotContains => $this->whereDoesntHaveValue($query, $field, fn (Builder $q) => $q->where('value', 'like', '%'.self::escapeLike((string) $value).'%')),
            SegmentOperator::StartsWith => $this->whereHasValue($query, $field, fn (Builder $q) => $q->where('value', 'like', self::escapeLike((string) $value).'%')),
            SegmentOperator::EndsWith => $this->whereHasValue($query, $field, fn (Builder $q) => $q->where('value', 'like', '%'.self::escapeLike((string) $value))),
            SegmentOperator::GreaterThan => $this->whereHasValue($query, $field, fn (Builder $q) => $numeric
                ? $q->whereRaw('CAST(value AS DECIMAL(24,6)) > ?', [(float) $value])
                : $q->where('value', '>', $value)),
            SegmentOperator::LessThan => $this->whereHasValue($query, $field, fn (Builder $q) => $numeric
                ? $q->whereRaw('CAST(value AS DECIMAL(24,6)) < ?', [(float) $value])
                : $q->where('value', '<', $value)),
            SegmentOperator::Between => $this->whereHasValue($query, $field, fn (Builder $q) => $numeric
                ? $q->whereRaw('CAST(value AS DECIMAL(24,6)) BETWEEN ? AND ?', [(float) $value, (float) $value2])
                : $q->whereBetween('value', [$value, $value2])),
            SegmentOperator::IsEmpty => $this->whereDoesntHaveValue($query, $field, fn (Builder $q) => $q->whereNotNull('value')->where('value', '!=', '')),
            SegmentOperator::NotEmpty => $this->whereHasValue($query, $field, fn (Builder $q) => $q->whereNotNull('value')->where('value', '!=', '')),
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function whereHasValue(Builder $query, ListField $field, callable $constraint): void
    {
        $query->whereHas('fieldValues', function (Builder $q) use ($field, $constraint): void {
            $q->where('list_field_id', $field->id);
            $constraint($q);
        });
    }

    private function whereDoesntHaveValue(Builder $query, ListField $field, callable $constraint): void
    {
        $query->whereDoesntHave('fieldValues', function (Builder $q) use ($field, $constraint): void {
            $q->where('list_field_id', $field->id);
            $constraint($q);
        });
    }

    private function applyColumnOperator(Builder $query, string $column, SegmentOperator $operator, ?string $value, ?string $value2): void
    {
        match ($operator) {
            SegmentOperator::Is => $query->where($column, $value),
            SegmentOperator::IsNot => $query->where($column, '!=', $value),
            SegmentOperator::Contains => $query->where($column, 'like', '%'.self::escapeLike((string) $value).'%'),
            SegmentOperator::NotContains => $query->where($column, 'not like', '%'.self::escapeLike((string) $value).'%'),
            SegmentOperator::StartsWith => $query->where($column, 'like', self::escapeLike((string) $value).'%'),
            SegmentOperator::EndsWith => $query->where($column, 'like', '%'.self::escapeLike((string) $value)),
            SegmentOperator::GreaterThan => $query->where($column, '>', $value),
            SegmentOperator::LessThan => $query->where($column, '<', $value),
            SegmentOperator::Between => $query->whereBetween($column, [$value, $value2]),
            SegmentOperator::IsEmpty => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '')),
            SegmentOperator::NotEmpty => $query->whereNotNull($column)->where($column, '!=', ''),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Date conditions (F-2.2.10-004): subscribed before/after/between and
     * last activity (max of last_open_at / last_click_at).
     *
     * @param  array<string, mixed>  $condition
     */
    private function applyDateCondition(Builder $query, array $condition, SegmentOperator $operator, ?string $value, ?string $value2): void
    {
        $key = (string) ($condition['field_key'] ?? ListSegmentCondition::KEY_SUBSCRIBED);

        $start = self::dayStart($value);
        $end = self::dayEnd($value);
        $end2 = self::dayEnd($value2);

        if ($key === ListSegmentCondition::KEY_LAST_ACTIVITY) {
            match ($operator) {
                SegmentOperator::After => $query->where(fn (Builder $q) => $q
                    ->where('last_open_at', '>', $end)
                    ->orWhere('last_click_at', '>', $end)),
                SegmentOperator::Before => $this->lastActivityBefore($query, $start),
                SegmentOperator::Between => $this->lastActivityBetween($query, $start, $end2),
                default => $query->whereRaw('1 = 0'),
            };

            return;
        }

        // subscribed_at may be null for legacy rows — fall back to created_at.
        $expr = 'COALESCE(subscribed_at, created_at)';

        match ($operator) {
            SegmentOperator::Before => $query->whereRaw($expr.' < ?', [$start]),
            SegmentOperator::After => $query->whereRaw($expr.' > ?', [$end]),
            SegmentOperator::Between => $query
                ->whereRaw($expr.' >= ?', [$start])
                ->whereRaw($expr.' <= ?', [$end2]),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Last activity strictly before $limit: has activity, none at/after $limit. */
    private function lastActivityBefore(Builder $query, ?string $limit): void
    {
        $query
            ->where(fn (Builder $q) => $q->whereNotNull('last_open_at')->orWhereNotNull('last_click_at'))
            ->where(fn (Builder $q) => $q->whereNull('last_open_at')->orWhere('last_open_at', '<', $limit))
            ->where(fn (Builder $q) => $q->whereNull('last_click_at')->orWhere('last_click_at', '<', $limit));
    }

    /** Last activity within [start, end]: none after end, at least one >= start. */
    private function lastActivityBetween(Builder $query, ?string $start, ?string $end): void
    {
        $query
            ->where(fn (Builder $q) => $q
                ->where('last_open_at', '>=', $start)
                ->orWhere('last_click_at', '>=', $start))
            ->where(fn (Builder $q) => $q->whereNull('last_open_at')->orWhere('last_open_at', '<=', $end))
            ->where(fn (Builder $q) => $q->whereNull('last_click_at')->orWhere('last_click_at', '<=', $end));
    }

    private function applyStatusCondition(Builder $query, SegmentOperator $operator, ?string $value): void
    {
        match ($operator) {
            SegmentOperator::Is => $query->where('status', $value),
            SegmentOperator::IsNot => $query->where('status', '!=', $value),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Campaign conditions (F-2.2.10-003): opened / did not open / clicked any
     * link / clicked a specific link / did not click — for ONE campaign or for
     * ANY campaign of this tenant, optionally restricted to the last N days.
     *
     * Evaluated against the WP-09/13 tracking tables, which are keyed by the
     * public uids (campaign_uid + subscriber_uid), so the correlation is
     * `campaign_track_*.subscriber_uid = subscribers.uid`.
     *
     * settings keys:
     *   campaign_id  — a campaign id owned by this list's customer; empty/absent
     *                  means "any campaign" (fenced to the tenant's campaigns).
     *   link_id      — campaign_track_urls.id, required by `clicked_link`.
     *   within_days  — 0/absent = no time window, else events newer than N days.
     *
     * Safety: an unknown or cross-tenant campaign/link id matches NOTHING in
     * both directions (`1 = 0`), the same convention applyFieldCondition() uses
     * for a deleted field — a broken condition must never silently widen a
     * segment into an unintended send.
     *
     * @param  array<string, mixed>  $condition
     */
    private function applyCampaignCondition(Builder $query, array $condition, SegmentOperator $operator, EmailList $list): void
    {
        /** @var array<string, mixed> $settings */
        $settings = is_array($condition['settings'] ?? null) ? $condition['settings'] : [];

        $campaignUid = $this->resolveCampaignUid($settings, $list, $broken);

        if ($broken) {
            $query->whereRaw('1 = 0');

            return;
        }

        $withinDays = (int) ($settings['within_days'] ?? 0);
        $since = $withinDays > 0 ? Carbon::now()->subDays($withinDays) : null;

        $linkId = null;

        if ($operator === SegmentOperator::ClickedLink) {
            $linkId = $this->trackedUrlId($settings, $list, $campaignUid);

            if ($linkId === null) {
                $query->whereRaw('1 = 0');

                return;
            }
        }

        $customerId = (int) $list->customer_id;
        $opens = EngagementQuery::TABLE_OPENS;
        $clicks = EngagementQuery::TABLE_CLICKS;

        match ($operator) {
            SegmentOperator::Opened => $this->engagement->whereEvent($query, $opens, $customerId, $campaignUid, $since, null, false),
            SegmentOperator::NotOpened => $this->engagement->whereEvent($query, $opens, $customerId, $campaignUid, $since, null, true),
            SegmentOperator::ClickedAny => $this->engagement->whereEvent($query, $clicks, $customerId, $campaignUid, $since, null, false),
            SegmentOperator::ClickedLink => $this->engagement->whereEvent($query, $clicks, $customerId, $campaignUid, $since, $linkId, false),
            SegmentOperator::NotClicked => $this->engagement->whereEvent($query, $clicks, $customerId, $campaignUid, $since, null, true),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Resolve settings[link_id] to a campaign_track_urls row that belongs to
     * this tenant (and to the chosen campaign when one is chosen).
     *
     * @param  array<string, mixed>  $settings
     */
    private function trackedUrlId(array $settings, EmailList $list, ?string $campaignUid): ?int
    {
        $rawLinkId = $settings['link_id'] ?? null;

        if ($rawLinkId === null || $rawLinkId === '' || (int) $rawLinkId <= 0) {
            return null;
        }

        $url = CampaignTrackUrl::query()->whereKey((int) $rawLinkId)->first();

        if ($url === null) {
            return null;
        }

        if ($campaignUid !== null) {
            return (string) $url->campaign_uid === $campaignUid ? (int) $url->id : null;
        }

        $owned = Campaign::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->where('uid', $url->campaign_uid)
            ->exists();

        return $owned ? (int) $url->id : null;
    }

    /**
     * settings[campaign_id] → campaign uid, fenced to this list's tenant.
     * Returns null for "any campaign of this tenant"; sets $broken when an id
     * WAS given but is unknown or belongs to another customer, which callers
     * must translate into "matches nothing in both directions" (D-90).
     *
     * @param  array<string, mixed>  $settings
     */
    private function resolveCampaignUid(array $settings, EmailList $list, ?bool &$broken): ?string
    {
        $broken = false;
        $rawCampaignId = $settings['campaign_id'] ?? null;

        if ($rawCampaignId === null || $rawCampaignId === '' || (int) $rawCampaignId <= 0) {
            return null;
        }

        $uid = Campaign::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->whereKey((int) $rawCampaignId)
            ->value('uid');

        if ($uid === null) {
            $broken = true;

            return null;
        }

        return (string) $uid;
    }

    /* ================================================================ *
     *  F-2.2.10-006 — engagement conditions
     * ================================================================ */

    /**
     * Engagement conditions (F-2.2.10-006): "opened at least N times" and
     * "no opens in the last N days", evaluated in the SAME query as every
     * other condition so the live count preview (F-2.2.10-010) and send-time
     * targeting stay one and the same predicate.
     *
     * value       — N (times for opened_at_least, days for no_opens_in_days).
     * settings:
     *   campaign_id — restrict to one campaign; absent = ANY campaign of this
     *                 tenant. An unknown/cross-tenant id matches NOTHING in
     *                 both directions (D-90).
     *   within_days — optional window for opened_at_least (0/absent = all time).
     *
     * N below 1 is clamped to 1 for opened_at_least ("at least 0 times" is
     * everybody, which would silently widen the segment). N below 1 for
     * no_opens_in_days means "no opens at all, ever".
     *
     * @param  array<string, mixed>  $condition
     */
    private function applyEngagementCondition(Builder $query, array $condition, SegmentOperator $operator, ?string $value, EmailList $list): void
    {
        /** @var array<string, mixed> $settings */
        $settings = is_array($condition['settings'] ?? null) ? $condition['settings'] : [];

        $campaignUid = $this->resolveCampaignUid($settings, $list, $broken);

        if ($broken) {
            $query->whereRaw('1 = 0');

            return;
        }

        $customerId = (int) $list->customer_id;
        $opens = EngagementQuery::TABLE_OPENS;
        $number = (int) $value;

        match ($operator) {
            SegmentOperator::OpenedAtLeast => $this->whereEventCountAtLeast(
                $query,
                $opens,
                $customerId,
                $campaignUid,
                max(1, $number),
                ((int) ($settings['within_days'] ?? 0)) > 0
                    ? Carbon::now()->subDays((int) $settings['within_days'])
                    : null,
            ),
            SegmentOperator::NoOpensInDays => $this->engagement->whereEvent(
                $query,
                $opens,
                $customerId,
                $campaignUid,
                $number > 0 ? Carbon::now()->subDays($number) : null,
                null,
                true,
            ),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Correlated "COUNT(*) >= N" over one tracking table. EngagementQuery only
     * answers EXISTS/NOT EXISTS, and it belongs to the subscribers module, so
     * the counting variant lives here — same tables, same tenant fence, same
     * uid correlation.
     */
    private function whereEventCountAtLeast(
        Builder $query,
        string $table,
        int $customerId,
        ?string $campaignUid,
        int $times,
        ?Carbon $since,
    ): void {
        $sub = DB::table($table)
            ->selectRaw('count(*)')
            ->whereColumn($table.'.subscriber_uid', 'subscribers.uid');

        if ($campaignUid !== null) {
            $sub->where($table.'.campaign_uid', $campaignUid);
        } else {
            self::fenceToTenantCampaigns($sub, $table, $customerId);
        }

        if ($since !== null) {
            $sub->where($table.'.created_at', '>=', $since);
        }

        $query->whereRaw('('.$sub->toSql().') >= ?', [...$sub->getBindings(), $times]);
    }

    /** Restrict a tracking-table subquery to the tenant's own campaigns. */
    private static function fenceToTenantCampaigns(QueryBuilder $sub, string $table, int $customerId): void
    {
        $sub->whereIn($table.'.campaign_uid', function (QueryBuilder $campaigns) use ($customerId): void {
            $campaigns->select('uid')
                ->from('campaigns')
                ->where('customer_id', $customerId)
                ->whereNull('deleted_at');
        });
    }

    /* ================================================================ *
     *  F-2.2.10-007 — geo conditions
     * ================================================================ */

    /**
     * Geo conditions (F-2.2.10-007): country / region / city / timezone.
     *
     * Sources are the platform's REAL recorded columns — no lookup is
     * performed while segmenting:
     *   timezone            → subscribers.timezone (WP-35 column);
     *   country/region/city → the geo columns WP-24 stamps on this
     *                         subscriber's open/click tracking rows, fenced to
     *                         the tenant's own campaigns. This is source (2) of
     *                         the same order-of-truth the subscriber profile
     *                         uses (F-2.2.5-003).
     *
     * Semantics on tracked geo, stated plainly because the two are not
     * complements for a traveller: `is/contains` = "was ever recorded there",
     * `is not/does not contain` = "was never recorded there" (which includes
     * subscribers with no geo at all), `is empty` = "no recorded location".
     *
     * @param  array<string, mixed>  $condition
     */
    private function applyGeoCondition(Builder $query, array $condition, SegmentOperator $operator, ?string $value, EmailList $list): void
    {
        $key = (string) ($condition['field_key'] ?? ListSegmentCondition::KEY_COUNTRY);

        if ($key === ListSegmentCondition::KEY_TIMEZONE) {
            // A real subscriber column: reuse the standard column operators.
            $this->applyColumnOperator($query, 'timezone', $operator, $value, null);

            return;
        }

        if (! in_array($key, [
            ListSegmentCondition::KEY_COUNTRY,
            ListSegmentCondition::KEY_REGION,
            ListSegmentCondition::KEY_CITY,
        ], true)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $predicate = match ($operator) {
            SegmentOperator::Is, SegmentOperator::IsNot => fn (QueryBuilder $sub, string $table): QueryBuilder => $sub
                ->where($table.'.'.$key, (string) $value),
            SegmentOperator::Contains, SegmentOperator::NotContains => fn (QueryBuilder $sub, string $table): QueryBuilder => $sub
                ->where($table.'.'.$key, 'like', '%'.self::escapeLike((string) $value).'%'),
            SegmentOperator::IsEmpty, SegmentOperator::NotEmpty => fn (QueryBuilder $sub, string $table): QueryBuilder => $sub
                ->whereNotNull($table.'.'.$key)
                ->where($table.'.'.$key, '!=', ''),
            default => null,
        };

        if ($predicate === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $customerId = (int) $list->customer_id;
        $tables = [EngagementQuery::TABLE_OPENS, EngagementQuery::TABLE_CLICKS];

        $negated = in_array($operator, [
            SegmentOperator::IsNot,
            SegmentOperator::NotContains,
            SegmentOperator::IsEmpty,
        ], true);

        if ($negated) {
            foreach ($tables as $table) {
                $query->whereNotExists(fn (QueryBuilder $sub) => $this->geoExists($sub, $table, $customerId, $predicate));
            }

            return;
        }

        $query->where(function (Builder $group) use ($tables, $customerId, $predicate): void {
            foreach ($tables as $index => $table) {
                $matcher = fn (QueryBuilder $sub) => $this->geoExists($sub, $table, $customerId, $predicate);

                $index === 0 ? $group->whereExists($matcher) : $group->orWhereExists($matcher);
            }
        });
    }

    /** One geo EXISTS body against a tracking table, tenant-fenced. */
    private function geoExists(QueryBuilder $sub, string $table, int $customerId, callable $predicate): void
    {
        $sub->selectRaw('1')
            ->from($table)
            ->whereColumn($table.'.subscriber_uid', 'subscribers.uid');

        self::fenceToTenantCampaigns($sub, $table, $customerId);

        $predicate($sub, $table);
    }

    /* ================================================================ *
     *  F-2.2.10-009 — automation conditions
     * ================================================================ */

    /**
     * Automation conditions (F-2.2.10-009): "in automation X" (an active run)
     * and "completed automation X" (a run that reached the end / met a goal),
     * read from automation_runs — the engine's own record of every journey.
     *
     * settings[automation_id] is required and re-fenced to this list's tenant:
     * an absent, unknown, soft-deleted or cross-tenant automation matches
     * NOTHING (D-90), never everybody.
     *
     * @param  array<string, mixed>  $condition
     */
    private function applyAutomationCondition(Builder $query, array $condition, SegmentOperator $operator, EmailList $list): void
    {
        if (! SegmentConditionType::Automation->isAvailable()) {
            $query->whereRaw('1 = 0');

            return;
        }

        /** @var array<string, mixed> $settings */
        $settings = is_array($condition['settings'] ?? null) ? $condition['settings'] : [];

        $automationId = $this->tenantAutomationId($settings, $list);

        $status = match ($operator) {
            SegmentOperator::InAutomation => AutomationRunStatus::Active,
            SegmentOperator::CompletedAutomation => AutomationRunStatus::Completed,
            default => null,
        };

        if ($automationId === null || $status === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $customerId = (int) $list->customer_id;

        $query->whereExists(function (QueryBuilder $sub) use ($automationId, $status, $customerId): void {
            $sub->selectRaw('1')
                ->from('automation_runs')
                ->whereColumn('automation_runs.subscriber_id', 'subscribers.id')
                ->where('automation_runs.automation_id', $automationId)
                ->where('automation_runs.customer_id', $customerId)
                ->where('automation_runs.status', $status->value);
        });
    }

    /** @param  array<string, mixed>  $settings */
    private function tenantAutomationId(array $settings, EmailList $list): ?int
    {
        $raw = $settings['automation_id'] ?? null;

        if ($raw === null || $raw === '' || (int) $raw <= 0) {
            return null;
        }

        // withoutTenancy() + explicit customer fence; soft-deleted automations
        // are excluded by the model's SoftDeletes scope.
        $id = Automation::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->whereKey((int) $raw)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Automation options for the segment condition builder (F-2.2.10-009) —
     * tenant-scoped, newest first.
     *
     * @return array<int, string>
     */
    public function automationOptions(EmailList $list): array
    {
        if (! SegmentConditionType::Automation->isAvailable()) {
            return [];
        }

        return Automation::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * Campaign options for the segment condition builder — tenant-scoped,
     * newest first (F-2.2.10-003 campaign picker).
     *
     * @return array<int, string>
     */
    public function campaignOptions(EmailList $list): array
    {
        return Campaign::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->orderByDesc('id')
            ->pluck('name', 'id')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /**
     * Tracked-link options for a chosen campaign (F-2.2.10-003 link picker).
     * Returns [] for an unknown/cross-tenant campaign so the select stays empty
     * instead of exposing another tenant's URLs.
     *
     * @return array<int, string>
     */
    public function linkOptions(EmailList $list, int|string|null $campaignId): array
    {
        if ($campaignId === null || $campaignId === '' || (int) $campaignId <= 0) {
            return [];
        }

        $campaignUid = Campaign::withoutTenancy()
            ->where('customer_id', $list->customer_id)
            ->whereKey((int) $campaignId)
            ->value('uid');

        if ($campaignUid === null) {
            return [];
        }

        return CampaignTrackUrl::query()
            ->where('campaign_uid', $campaignUid)
            ->orderBy('id')
            ->pluck('url', 'id')
            ->map(fn ($url): string => (string) $url)
            ->all();
    }

    /**
     * Standard field options for segment forms — id-less keys for standard
     * columns plus the list's custom fields.
     *
     * @return array<string, string>
     */
    public function fieldOptions(EmailList $list): array
    {
        $options = [ListSegmentCondition::KEY_EMAIL => __('segments.fields.email')];

        foreach ($list->fields()->get() as $field) {
            $options['field:'.$field->id] = $field->label;
        }

        return $options;
    }

    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private static function dayStart(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) === 10 ? $value.' 00:00:00' : $value;
    }

    private static function dayEnd(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return strlen($value) === 10 ? $value.' 23:59:59' : $value;
    }
}
