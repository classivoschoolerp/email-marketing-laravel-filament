<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\TypedField;
use App\Enums\ListFieldType;
use App\Models\Concerns\BelongsToCustomer;
use App\Models\Concerns\IsTypedField;
use Database\Factories\ListFieldFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A list custom field (spec §2.2.9).
 *
 * WP-31: also the reference implementation of {@see TypedField} — form
 * fields (§2.7) and survey questions (§2.8) reuse this type library and the
 * same FieldValueService rather than growing their own.
 *
 * @property array<string, mixed>|null $validation
 */
class ListField extends Model implements TypedField
{
    use BelongsToCustomer;

    /** @use HasFactory<ListFieldFactory> */
    use HasFactory;

    use IsTypedField;

    /** Merge tags that belong to the standard schema (F-2.2.9-006). */
    public const RESERVED_TAGS = [
        'EMAIL', 'UID', 'STATUS', 'SOURCE', 'IP',
        'SUBSCRIBE_DATE', 'CONFIRM_DATE', 'UNSUBSCRIBE_DATE',
        'LIST_NAME', 'LIST_UID', 'UNSUBSCRIBE_URL', 'CONFIRM_URL',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ListFieldType::class,
            'required' => 'boolean',
            // F-2.2.9-005: unique-value enforcement, enforced in FieldValueService.
            'is_unique' => 'boolean',
            'visible_on_form' => 'boolean',
            'visible_on_profile' => 'boolean',
            'show_in_table' => 'boolean',
            'sort_order' => 'integer',
            'validation' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $field): void {
            // Merge tags are canonically uppercase (F-2.2.9-002).
            $field->tag = strtoupper(trim((string) $field->tag));
        });
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(EmailList::class, 'list_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ListFieldOption::class)->orderBy('sort_order');
    }

    public function values(): HasMany
    {
        return $this->hasMany(SubscriberFieldValue::class);
    }

    public static function isReservedTag(string $tag): bool
    {
        return in_array(strtoupper(trim($tag)), self::RESERVED_TAGS, true);
    }
}
