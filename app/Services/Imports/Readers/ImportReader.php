<?php

declare(strict_types=1);

namespace App\Services\Imports\Readers;

use App\Models\ImportJob;

/**
 * A source of subscriber rows for one import run (spec §2.2.7 sources a–e).
 * prepare() runs once before the first row (download, auto-create fields,
 * finalize the mapping); rows() must support starting at an offset so the
 * queued job can resume after a time-budget requeue.
 */
interface ImportReader
{
    public function prepare(ImportJob $job): void;

    public function totalRows(ImportJob $job): int;

    /**
     * @return \Generator<int, array{line: int, email: string, fields: array<string, mixed>}>
     */
    public function rows(ImportJob $job, int $offset): \Generator;
}
