<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable record of which test-group subscriber received which variant
 * (spec §2.3.6 F-2.3.6-009). Insert-only, global (keyed by uid like every
 * other attribution table).
 */
class CampaignVariantRecipient extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(CampaignVariant::class, 'campaign_variant_id');
    }
}
