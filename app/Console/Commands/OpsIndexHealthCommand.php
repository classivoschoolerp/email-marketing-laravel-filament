<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\IndexHealthChecker;
use Illuminate\Console\Command;

/**
 * `ops:index-health` (spec §6.6 F-6.6-004, WP-24): missing composite indexes,
 * duplicate indexes and — on MySQL with performance_schema — unused indexes.
 * Exit code 1 when something is missing, so it can gate a deploy.
 */
class OpsIndexHealthCommand extends Command
{
    protected $signature = 'ops:index-health {--json : machine-readable output}';

    protected $description = 'Check database index health (§6.6 F-6.6-004).';

    public function handle(IndexHealthChecker $checker): int
    {
        $report = $checker->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['missing'] === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info(__('ops.index_health.driver', ['driver' => $report['driver']]));

        if ($report['missing'] === []) {
            $this->info(__('ops.index_health.no_missing'));
        } else {
            $this->warn(__('ops.index_health.missing_heading'));

            foreach ($report['missing'] as $row) {
                $this->line('  '.$row['ddl']);
            }
        }

        if ($report['duplicates'] !== []) {
            $this->warn(__('ops.index_health.duplicates_heading'));

            foreach ($report['duplicates'] as $row) {
                $this->line('  '.__('ops.index_health.duplicate_line', [
                    'table' => $row['table'],
                    'index' => $row['index'],
                    'other' => $row['duplicate_of'],
                ]));
            }
        }

        if (! $report['unused']['supported']) {
            $this->line(__('ops.index_health.unused_unavailable', ['note' => (string) $report['unused']['note']]));
        } elseif ($report['unused']['rows'] === []) {
            $this->info(__('ops.index_health.no_unused'));
        } else {
            $this->warn(__('ops.index_health.unused_heading'));

            foreach ($report['unused']['rows'] as $row) {
                $this->line('  '.$row['table'].'.'.$row['index']);
            }
        }

        return $report['missing'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
