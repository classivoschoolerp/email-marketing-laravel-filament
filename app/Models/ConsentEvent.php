<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit record of one consent-field change (spec §4.5
 * F-4.5-008). Tenant-scoped through {@see BelongsToCustomer} so the customer
 * panel only ever sees its own subscribers' consent history.
 */
class ConsentEvent extends Model
{
    use BelongsToCustomer;

    public const UPDATED_AT = null;

    /** Where the change came from. */
    public const SOURCE_PUBLIC = 'public';

    public const SOURCE_CUSTOMER = 'customer';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_API = 'api';

    public const SOURCE_SYSTEM = 'system';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ListField::class, 'list_field_id');
    }

    /** Human label for a stored consent value ("1"/"0"/NULL). */
    public static function valueLabel(?string $value): string
    {
        return match ($value) {
            '1' => __('privacy.consent_events.granted'),
            '0' => __('privacy.consent_events.withdrawn'),
            default => __('privacy.consent_events.none'),
        };
    }
}
