<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One feature toggle of the per-group permission matrix (spec §0.3 F-0.3-004).
 */
class CustomerGroupPermission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }
}
