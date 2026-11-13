<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A dedicated sending IP inside an IP pool (spec §3.13.1 F-3.13.1-009).
 * Optionally bound to the delivery server that sends from it, which is what
 * lets the deliverability centre check the right addresses against the
 * DNSBLs (§2.9.9 F-2.9.9-003).
 */
class DeliveryServerIp extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'dedicated' => true,
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'dedicated' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DeliveryServerGroup::class, 'delivery_server_group_id')->withoutGlobalScopes();
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DeliveryServer::class, 'delivery_server_id')->withoutGlobalScopes();
    }
}
