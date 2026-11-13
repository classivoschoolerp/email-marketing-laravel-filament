<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Sending\DeliveryServerAssignments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "this server may only be used for X" row (spec §2.9.2 F-2.9.2-038).
 *
 * Deliberately NOT tenant-scoped: the row hangs off delivery_servers, which
 * already carries the tenancy, and the send path reads it while running as the
 * campaign's customer. Cross-tenant ids are impossible to write because
 * {@see DeliveryServerAssignments::sync()} is the only
 * writer and it validates ownership first.
 */
class DeliveryServerAssignment extends Model
{
    public const RESOURCE_LIST = 'list';

    public const RESOURCE_CAMPAIGN = 'campaign';

    /** @var list<string> */
    public const RESOURCE_TYPES = [self::RESOURCE_LIST, self::RESOURCE_CAMPAIGN];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(DeliveryServer::class, 'delivery_server_id');
    }
}
