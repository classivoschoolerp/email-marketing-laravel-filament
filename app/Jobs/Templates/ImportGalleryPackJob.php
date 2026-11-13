<?php

declare(strict_types=1);

namespace App\Jobs\Templates;

use App\Services\Templates\GalleryPackImporter;
use App\Support\Queue\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Queued bulk gallery pack import (spec §3.14 F-3.14-003; docs/ARCHITECTURE.md rule 5 —
 * imports never run synchronously). The uploaded pack lives on the local disk
 * until processed, then is deleted.
 */
class ImportGalleryPackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly string $storedPath)
    {
        $this->onQueue(Queues::IMPORTS);
    }

    public function handle(GalleryPackImporter $importer): void
    {
        $disk = Storage::disk('local');

        try {
            $importer->import($disk->path($this->storedPath));
        } finally {
            $disk->delete($this->storedPath);
        }
    }
}
