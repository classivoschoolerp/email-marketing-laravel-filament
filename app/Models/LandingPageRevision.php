<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stored landing-page content save (spec §2.6 F-2.6-005).
 *
 * Not tenant-scoped in its own right — it is reachable only through its
 * parent page, which is (exactly like `template_revisions`).
 */
class LandingPageRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LandingPage::class, 'landing_page_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(LandingPageVariant::class, 'landing_page_variant_id');
    }
}
