<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Option row for choice-type custom fields (spec F-2.2.9-003).
 */
class ListFieldOption extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ListField::class, 'list_field_id');
    }
}
