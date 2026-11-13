<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One processed FBL/ARF complaint (spec §2.9.5 F-2.9.5-004 / §3.27, WP-15).
 * Append-only; nullable attribution; not tenant-scoped (see
 * CampaignBounceLog for rationale).
 */
class CampaignComplaintLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
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

    public function fblServer(): BelongsTo
    {
        return $this->belongsTo(FblServer::class)->withoutGlobalScopes();
    }
}
