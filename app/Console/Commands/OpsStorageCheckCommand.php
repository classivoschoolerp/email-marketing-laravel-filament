<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Ops\ObjectStorage;
use Illuminate\Console\Command;

/**
 * `ops:storage-check` (spec §0.1 F-0.1-009, WP-24): proves the configured
 * upload disk really works with a write → read → delete round trip. Run it
 * after switching a production install to S3/MinIO/R2 — a misconfigured
 * bucket otherwise only shows up when a customer's export vanishes.
 */
class OpsStorageCheckCommand extends Command
{
    protected $signature = 'ops:storage-check {--disk= : check a specific disk instead of the configured one}';

    protected $description = 'Round-trip test the object-storage disk (§0.1 F-0.1-009).';

    public function handle(): int
    {
        $disk = $this->option('disk');
        $result = ObjectStorage::smokeTest(is_string($disk) && $disk !== '' ? $disk : null);

        if ($result['ok']) {
            $this->info(sprintf('Disk "%s" (%s driver) round-tripped successfully.', $result['disk'], $result['driver']));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Disk "%s" (%s driver) failed: %s',
            $result['disk'],
            $result['driver'],
            (string) $result['error'],
        ));

        return self::FAILURE;
    }
}
