<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * §2.15.3 one stored deviation from a NotificationEvent's default (WP-39).
 * A missing row means "use the default", so the table stays small and new
 * events never need a backfill.
 */
class NotificationPreference extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ];
    }

    public function subUser(): BelongsTo
    {
        return $this->belongsTo(SubUser::class);
    }
}
