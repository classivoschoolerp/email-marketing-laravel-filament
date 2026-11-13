<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw text storage for a subscriber's custom-field value (spec §2.2.9).
 * Typed reads/writes go through App\Services\Lists\FieldValueService.
 */
class SubscriberFieldValue extends Model
{
    use BelongsToCustomer;

    protected $guarded = ['id'];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ListField::class, 'list_field_id');
    }
}
