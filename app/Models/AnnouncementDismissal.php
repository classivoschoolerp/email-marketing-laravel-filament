<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One customer dismissing one announcement (spec §2.1 F-2.1-010, WP-18).
 * Deliberately NOT tenant-scoped via BelongsToCustomer: rows are only ever
 * written/read through the owning announcement + an explicit customer id.
 */
class AnnouncementDismissal extends Model
{
    protected $guarded = ['id'];

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
