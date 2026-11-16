<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only journey trail row (spec §2.4.3). Insert-only, no updated_at —
 * same shape as the WP-09 tracking tables.
 */
class AutomationRunLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_ENTERED = 'entered';

    public const ACTION_REACHED = 'reached';

    public const ACTION_EXECUTED = 'executed';

    public const ACTION_BRANCH = 'branch';

    public const ACTION_EMAIL_SENT = 'email_sent';

    public const ACTION_GOAL_MET = 'goal_met';

    public const ACTION_ERROR = 'error';

    public const ACTION_COMPLETED = 'completed';

    public const ACTION_EXITED = 'exited';

    public const ACTION_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
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
