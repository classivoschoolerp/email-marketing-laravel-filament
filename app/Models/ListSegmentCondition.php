<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One segment condition row (spec §2.2.10).
 *
 * @property array<string, mixed>|null $settings
 */
class ListSegmentCondition extends Model
{
    use BelongsToCustomer;

    /** Standard (non-custom-field) keys usable in field/date conditions. */
    public const KEY_EMAIL = 'email';

    public const KEY_SUBSCRIBED = 'subscribed';

    public const KEY_LAST_ACTIVITY = 'last_activity';

    /** Geo keys (F-2.2.10-007). */
    public const KEY_COUNTRY = 'country';

    public const KEY_REGION = 'region';

    public const KEY_CITY = 'city';

    public const KEY_TIMEZONE = 'timezone';

    /** @return list<string> */
    public static function geoKeys(): array
    {
        return [self::KEY_COUNTRY, self::KEY_REGION, self::KEY_CITY, self::KEY_TIMEZONE];
    }

    /** @return list<string> every field_key the condition vocabulary accepts */
    public static function fieldKeys(): array
    {
        return [self::KEY_EMAIL, self::KEY_SUBSCRIBED, self::KEY_LAST_ACTIVITY, ...self::geoKeys()];
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => SegmentConditionType::class,
            'operator' => SegmentOperator::class,
            'settings' => 'array',
            'sort_order' => 'integer',
            // Nested condition groups (F-2.2.10-011): 0 = the default group.
            'group_index' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // group_index is NOT NULL with a 0 default, but every writer (the
        // repeater, the API, save-search) may legitimately leave it blank —
        // "no group" is group 0, normalised here so no call site has to know.
        static::saving(function (self $condition): void {
            $condition->group_index = max(0, (int) $condition->group_index);

            if ($condition->group_match === '') {
                $condition->group_match = null;
            }
        });
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ListSegment::class, 'list_segment_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ListField::class, 'list_field_id');
    }
}
