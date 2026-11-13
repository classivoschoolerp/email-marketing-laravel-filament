<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * §6.6 F-6.6-003 recorded slow query. Install-wide operations data — not
 * tenant-scoped. Log table: created_at only.
 */
class SlowQuery extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'time_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
