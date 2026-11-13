<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §6.6 F-6.6-004 — MySQL query optimisation / index health check (WP-24).
 *
 * Three checks, all read-only:
 *  1. **Expected composite indexes.** The hot subscriber lookup shape is
 *     `(customer_id, list_id, status, email)`; the send/report paths need the
 *     campaign_uid/subscriber_uid pairs and the delivery-log message_id join
 *     (D-65). A missing one is reported with the exact DDL to add it.
 *  2. **Duplicate indexes** — two indexes on the same table whose column list
 *     is a prefix of the other's. Pure waste on write throughput.
 *  3. **Unused indexes** — only meaningful when performance_schema is on;
 *     otherwise reported as "not available" rather than guessed.
 *
 * On SQLite (the D-03 dev/test target) checks 1 and 2 still run using the
 * portable schema introspection, and check 3 reports honestly that it
 * requires MySQL. Nothing here is ever silently skipped.
 */
class IndexHealthChecker
{
    /**
     * table => list of expected composite indexes (column lists).
     *
     * @var array<string, list<list<string>>>
     */
    public const EXPECTED = [
        'subscribers' => [
            ['customer_id', 'list_id', 'status', 'email'],
        ],
        'campaign_track_opens' => [
            ['campaign_uid', 'subscriber_uid'],
        ],
        'campaign_track_clicks' => [
            ['campaign_uid', 'subscriber_uid'],
        ],
        'campaign_delivery_logs' => [
            ['message_id'],
        ],
    ];

    public function driver(): string
    {
        return (string) DB::connection()->getDriverName();
    }

    public function isMysql(): bool
    {
        return in_array($this->driver(), ['mysql', 'mariadb'], true);
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        return [
            'driver' => $this->driver(),
            'missing' => $this->missingIndexes(),
            'duplicates' => $this->duplicateIndexes(),
            'unused' => $this->unusedIndexes(),
        ];
    }

    /**
     * @return list<array{table: string, columns: list<string>, ddl: string}>
     */
    public function missingIndexes(): array
    {
        $missing = [];

        foreach (self::EXPECTED as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existing = $this->indexesFor($table);

            foreach ($indexes as $columns) {
                $available = array_filter(
                    $columns,
                    static fn (string $column): bool => Schema::hasColumn($table, $column),
                );

                if (count($available) !== count($columns)) {
                    continue; // Column not in this schema version — not an index problem.
                }

                if (! $this->covered($existing, $columns)) {
                    $missing[] = [
                        'table' => $table,
                        'columns' => $columns,
                        'ddl' => sprintf(
                            'CREATE INDEX %s ON %s (%s);',
                            $table.'_'.implode('_', $columns).'_index',
                            $table,
                            implode(', ', $columns),
                        ),
                    ];
                }
            }
        }

        return $missing;
    }

    /**
     * @return list<array{table: string, index: string, duplicate_of: string, columns: list<string>}>
     */
    public function duplicateIndexes(): array
    {
        $duplicates = [];

        foreach (array_keys(self::EXPECTED) as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $indexes = $this->indexesFor($table);

            foreach ($indexes as $name => $columns) {
                foreach ($indexes as $otherName => $otherColumns) {
                    if ($name === $otherName || count($columns) > count($otherColumns)) {
                        continue;
                    }

                    if (count($columns) === count($otherColumns) && strcmp($name, $otherName) >= 0) {
                        continue; // Report an identical pair only once.
                    }

                    if (array_slice($otherColumns, 0, count($columns)) === $columns) {
                        $duplicates[] = [
                            'table' => $table,
                            'index' => $name,
                            'duplicate_of' => $otherName,
                            'columns' => $columns,
                        ];

                        break;
                    }
                }
            }
        }

        return $duplicates;
    }

    /**
     * @return array{supported: bool, note: ?string, rows: list<array{table: string, index: string}>}
     */
    public function unusedIndexes(): array
    {
        if (! $this->isMysql()) {
            return [
                'supported' => false,
                'note' => __('ops.index_health.requires_mysql'),
                'rows' => [],
            ];
        }

        try {
            $rows = DB::select(
                'select object_name as `table`, index_name as `index`'
                .' from performance_schema.table_io_waits_summary_by_index_usage'
                .' where index_name is not null'
                .'   and index_name != "PRIMARY"'
                .'   and count_star = 0'
                .'   and object_schema = database()'
                .' order by object_name, index_name'
            );
        } catch (\Throwable $exception) {
            return [
                'supported' => false,
                'note' => __('ops.index_health.performance_schema_off', ['error' => $exception->getMessage()]),
                'rows' => [],
            ];
        }

        return [
            'supported' => true,
            'note' => null,
            'rows' => array_map(
                static fn (object $row): array => ['table' => (string) $row->table, 'index' => (string) $row->index],
                $rows,
            ),
        ];
    }

    /**
     * Index name => ordered column list, driver-aware.
     *
     * @return array<string, list<string>>
     */
    public function indexesFor(string $table): array
    {
        if ($this->isMysql()) {
            return $this->mysqlIndexes($table);
        }

        if ($this->driver() === 'sqlite') {
            return $this->sqliteIndexes($table);
        }

        return [];
    }

    /** @return array<string, list<string>> */
    private function mysqlIndexes(string $table): array
    {
        $rows = DB::select(
            'select index_name as name, column_name as column_name, seq_in_index as position'
            .' from information_schema.statistics'
            .' where table_schema = database() and table_name = ?'
            .' order by index_name, seq_in_index',
            [$table],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $indexes[(string) $row->name][] = (string) $row->column_name;
        }

        return $indexes;
    }

    /** @return array<string, list<string>> */
    private function sqliteIndexes(string $table): array
    {
        $indexes = [];

        foreach (DB::select('pragma index_list("'.$table.'")') as $index) {
            $name = (string) ($index->name ?? '');

            if ($name === '') {
                continue;
            }

            $columns = [];

            foreach (DB::select('pragma index_info("'.$name.'")') as $column) {
                $columns[] = (string) $column->name;
            }

            $indexes[$name] = $columns;
        }

        return $indexes;
    }

    /**
     * An expected index is "covered" when some existing index starts with the
     * same column sequence (a leftmost-prefix match, the way MySQL uses it).
     *
     * @param  array<string, list<string>>  $existing
     * @param  list<string>  $columns
     */
    private function covered(array $existing, array $columns): bool
    {
        foreach ($existing as $indexColumns) {
            if (array_slice($indexColumns, 0, count($columns)) === $columns) {
                return true;
            }
        }

        return false;
    }
}
