<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use App\Services\Lists\FieldValueService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Choice option for a form field or a survey question (WP-31). Mirrors
 * {@see ListFieldOption} (value + label + sort_order) so the public field
 * partial and {@see FieldValueService} treat all three
 * field families identically.
 */
class FieldOption extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
