<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved campaign-content version (spec §2.3.3 F-2.3.3-019). Mirrors
 * TemplateRevision; insert-only.
 */
class CampaignRevision extends Model
{
    public const UPDATED_AT = null;

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_AUTOSAVE = 'autosave';

    public const ORIGIN_RESTORE = 'restore';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
