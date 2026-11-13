<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-customer quota override row (spec §3.4 F-3.4-008). Shares its key
 * namespace with customer_group_limits; QuotaService::limitFor() reads the
 * customer row BEFORE the group row. value null = unlimited.
 */
class CustomerLimit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
