<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subscriber's learned send-time profile (spec §2.3.4 F-2.3.4-011, WP-46).
 *
 * A cache of a computation over campaign_track_opens/campaign_track_clicks,
 * never a source of truth: delete every row and `send-time:profile` rebuilds
 * them identically. See App\Services\Sending\SendTimeProfiler for the weights.
 */
class SubscriberSendTimeProfile extends Model
{
    use BelongsToCustomer;

    /** The subscriber's own open/click history was rich enough to speak. */
    public const SOURCE_SUBSCRIBER = 'subscriber';

    /** Fell back to the tenant's account-level best hour. */
    public const SOURCE_ACCOUNT = 'account';

    /** Nothing to say — the campaign's own send time is used unchanged. */
    public const SOURCE_NONE = 'none';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'best_hour' => 'integer',
            'best_day' => 'integer',
            'confidence' => 'float',
            'events' => 'integer',
            'weight_total' => 'float',
            'histogram' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** True when this row carries a usable per-recipient prediction. */
    public function predicts(): bool
    {
        return $this->best_hour !== null && $this->source !== self::SOURCE_NONE;
    }

    /** The profile's timezone, or UTC when the subscriber's was never recorded. */
    public function zone(): string
    {
        $zone = trim((string) $this->timezone);

        return $zone === '' ? 'UTC' : $zone;
    }
}
