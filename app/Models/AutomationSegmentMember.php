<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Segment membership snapshot row for enters/exits-segment triggers
 * (F-2.4.2-018). Engine-internal; insert/delete only.
 */
class AutomationSegmentMember extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
