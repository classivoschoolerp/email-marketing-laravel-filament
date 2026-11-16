<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Outbound webhook call log (F-2.4.2-030, F-2.4.3-006). */
class AutomationWebhookLog extends Model
{
    use BelongsToCustomer;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status_code' => 'integer',
            'attempts' => 'integer',
        ];
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
}
