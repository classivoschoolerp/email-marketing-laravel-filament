<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One open-pixel hit (spec §1.7 F-1.7-001/006). Insert-only log table:
 * created_at only, no updated_at. Global — resolved by uid columns.
 */
class CampaignTrackOpen extends Model
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
