<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One completed landing-page form submission (spec §2.6 F-2.6-008/009).
 *
 * The §2.7 form module owns the subscriber write and its own submission row;
 * this is the landing-side fact used by the index counters and the A/B
 * comparison.
 */
class LandingPageConversion extends Model
{
    use BelongsToCustomer;

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

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
