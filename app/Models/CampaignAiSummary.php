<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cached plain-English performance summary for one campaign
 * (spec §2.15.8 F-2.15.8-008, WP-46).
 *
 * Tenant-owned, so the standard global scope applies. `stats` is the exact
 * measured fact set the prose was written about — the model was given those
 * numbers and asked to describe them, never to compute or rate anything
 * (D-105).
 *
 * @property array<string, mixed>|null $stats
 */
class CampaignAiSummary extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'credits' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
