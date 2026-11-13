<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One suppressed address inside a suppression list (§2.9.11). Tenancy is
 * inherited through the owning SuppressionList (no customer_id column of
 * its own); addresses are lower-cased on create.
 */
class SuppressionListEmail extends Model
{
    protected $guarded = ['id'];

    public function suppressionList(): BelongsTo
    {
        return $this->belongsTo(SuppressionList::class);
    }

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->email = mb_strtolower(trim((string) $row->email));
        });
    }
}
