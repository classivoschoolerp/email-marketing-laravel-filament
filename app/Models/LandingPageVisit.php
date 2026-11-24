<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Services\Landing\LandingPageTracking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One landing-page view (spec §2.6 F-2.6-008, §1.8 F-1.8-003).
 *
 * Written only by {@see LandingPageTracking}; `ip` has
 * already passed through IpAnonymizer by the time it gets here.
 */
class LandingPageVisit extends Model
{
    use BelongsToCustomer;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'converted' => 'boolean',
            'created_at' => 'datetime',
        ];
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
