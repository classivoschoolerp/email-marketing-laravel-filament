<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-recipient delivery log (WP-13, spec §0.6/§4.6). The unique
 * (campaign_id, subscriber_id) constraint is the second idempotency arbiter
 * for send jobs (F-0.4-004 / F-4.6-002). Global — no tenant scope: the send
 * path runs in workers outside any authenticated context.
 */
class CampaignDeliveryLog extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retries' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class)->withoutGlobalScopes();
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class)->withoutGlobalScopes();
    }

    public function deliveryServer(): BelongsTo
    {
        return $this->belongsTo(DeliveryServer::class)->withoutGlobalScopes();
    }
}
