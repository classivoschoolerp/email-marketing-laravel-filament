<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ops\CacheWarmer;
use Illuminate\Console\Command;

/**
 * `emp:cache-warm` (spec §0.1 F-0.1-016, WP-24): pre-computes the expensive
 * dashboard/report aggregates into the smart cache so the first admin page
 * view after a deploy is fast. Scheduled hourly from routes/modules/ops.php.
 */
class CacheWarmCommand extends Command
{
    protected $signature = 'emp:cache-warm {--flush : clear the buckets before warming}';

    protected $description = 'Warm the smart caching layer for dashboard/report queries (§0.1).';

    public function handle(CacheWarmer $warmer): int
    {
        if ($this->option('flush')) {
            $flushed = $warmer->flush();

            $this->line($flushed
                ? __('ops.cache.flushed')
                : __('ops.cache.flush_unsupported', ['driver' => (string) config('cache.default')]));
        }

        $warmed = $warmer->warm();

        foreach ($warmed as $key => $value) {
            $this->line(__('ops.cache.warmed_entry', ['key' => $key, 'value' => (string) $value]));
        }

        $this->info(__('ops.cache.warmed', ['count' => count($warmed)]));

        return self::SUCCESS;
    }
}
