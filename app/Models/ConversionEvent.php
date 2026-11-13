<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One attributed conversion (spec §2.3.4 F-2.3.4-024, §2.3.8 F-2.3.8-002,
 * §2.12 F-2.12-007, §4.2 F-4.2-009 — WP-45).
 *
 * NO tenancy trait, deliberately, and this is the same shape as the WP-09
 * `campaign_track_*` log models: rows are written by PUBLIC endpoints (the
 * conversion pixel) and by queued jobs, neither of which has an authenticated
 * tenant in scope. `customer_id` is still stored and every reader fences on
 * it explicitly — the global scope would simply have silenced those writes.
 *
 * `value` is integer MINOR UNITS.
 *
 * @property int $customer_id
 * @property int $value
 */
class ConversionEvent extends Model
{
    public const UPDATED_AT = null;

    public const ORIGIN_PIXEL = 'pixel';

    public const ORIGIN_STORE = 'store';

    public const TOUCH_CLICK = 'click';

    public const TOUCH_OPEN = 'open';

    public const TOUCH_AUTOMATION_EMAIL = 'automation_email';

    public const TOUCH_PIXEL = 'pixel';

    protected $guarded = ['id'];

    protected $attributes = [
        'value' => 0,
        'currency' => 'USD',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
