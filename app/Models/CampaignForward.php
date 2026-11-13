<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One forward-to-a-friend event (spec §1.6 F-1.6-002, §1.7 F-1.7-004).
 * Global and uid-keyed, exactly like the other §1.7 tracking tables.
 */
class CampaignForward extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
