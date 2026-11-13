<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily send rollup per delivery server (WP-14; powers daily/monthly quota
 * headroom checks and the §2.9.1 usage columns). Not tenant-scoped: rows are
 * only ever reached through their server, and system servers are shared.
 */
class DeliveryServerUsage extends Model
{
    protected $table = 'delivery_server_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sent' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DeliveryServer::class, 'delivery_server_id');
    }
}
