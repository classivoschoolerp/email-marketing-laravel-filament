<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applies monthly RANGE partitioning to the high-volume tables (spec §0.1). MySQL-only
 * DDL that the schema builder cannot express — safe no-op elsewhere (docs/DECISIONS.md D-03).
 * Re-running adds missing future partitions; schedule monthly in production.
 */
class DbPartition extends Command
{
    protected $signature = 'db:partition {--months=24 : Future monthly partitions to ensure}';

    protected $description = 'Partition high-volume tables by month (MySQL only)';

    private const TABLES = [
        'subscribers',
        'campaign_delivery_logs',
        'campaign_track_opens',
        'campaign_track_clicks',
    ];

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->info('Non-MySQL driver — nothing to do.');

            return self::SUCCESS;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Table {$table} does not exist yet — skipped.");

                continue;
            }

            $this->partition($table);
        }

        return self::SUCCESS;
    }

    private function partition(string $table): void
    {
        $db = DB::getDatabaseName();

        $existing = collect(DB::select(
            'SELECT PARTITION_NAME name FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
            [$db, $table],
        ))->pluck('name');

        $wanted = $this->wantedPartitions();

        if ($existing->isEmpty()) {
            // MySQL requires the partition key inside every unique key: rebuild the PK.
            DB::statement("ALTER TABLE `{$table}` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`, `created_at`)");

            $clauses = $wanted
                ->map(fn (array $p): string => "PARTITION {$p['name']} VALUES LESS THAN ({$p['bound']})")
                ->push('PARTITION pMAX VALUES LESS THAN MAXVALUE')
                ->implode(",\n");

            DB::statement("ALTER TABLE `{$table}` PARTITION BY RANGE (TO_DAYS(`created_at`)) (\n{$clauses}\n)");
            $this->info("{$table}: partitioned with {$wanted->count()} monthly partitions + pMAX.");

            return;
        }

        $missing = $wanted->reject(fn (array $p): bool => $existing->contains($p['name']));

        foreach ($missing as $p) {
            DB::statement(
                "ALTER TABLE `{$table}` REORGANIZE PARTITION pMAX INTO (
                    PARTITION {$p['name']} VALUES LESS THAN ({$p['bound']}),
                    PARTITION pMAX VALUES LESS THAN MAXVALUE
                )",
            );
        }

        $this->info("{$table}: added {$missing->count()} missing partitions.");
    }

    /** @return Collection<int, array{name: string, bound: string}> */
    private function wantedPartitions(): Collection
    {
        $months = max(1, (int) $this->option('months'));

        return collect(range(0, $months - 1))->map(function (int $i): array {
            $start = Carbon::now()->startOfMonth()->addMonths($i);
            $bound = $start->copy()->addMonth();

            return [
                'name' => 'p'.$start->format('Ym'),
                'bound' => "TO_DAYS('".$bound->format('Y-m-d')."')",
            ];
        });
    }
}
