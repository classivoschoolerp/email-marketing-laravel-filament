<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Automation email delivery + engagement row (F-2.4.2-020, §2.4.3).
 * Status vocabulary mirrors CampaignDeliveryLog.
 */
class AutomationEmailSend extends Model
{
    use BelongsToCustomer;

    public const STATUS_RETRYING = 'retrying';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'retries' => 'integer',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $send): void {
            $send->uid ??= (string) Str::uuid();
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class)->withoutGlobalScopes();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'run_id')->withoutGlobalScopes();
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(AutomationNode::class, 'node_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class)->withoutGlobalScopes();
    }
}
