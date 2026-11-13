<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BounceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One processed bounce message (spec §2.9.4 F-2.9.4-009 / §3.27, WP-15).
 * Append-only: created_at only, no updates. Attribution columns are nullable
 * — unmatched DSNs still get a row. Deliberately NOT tenant-scoped: rows are
 * written by the global bounce cron and read in the ADMIN panel; customer_id
 * is carried as plain data for later customer-facing log views.
 */
class CampaignBounceLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bounce_type' => BounceType::class,
            'created_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class)->withoutGlobalScopes();
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScopes();
    }

    public function bounceServer(): BelongsTo
    {
        return $this->belongsTo(BounceServer::class)->withoutGlobalScopes();
    }
}
