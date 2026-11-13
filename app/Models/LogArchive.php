<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One cold-storage archive file written by `logs:prune` (spec §4.6
 * F-4.6-008, WP-42). Operator-owned and install-wide — deliberately NOT
 * tenant-scoped; see the migration for why.
 */
class LogArchive extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rows_count' => 'integer',
            'bytes' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    /** True when the file is still on the disk it was written to. */
    public function exists(): bool
    {
        return Storage::disk((string) $this->disk)->exists((string) $this->path);
    }
}
