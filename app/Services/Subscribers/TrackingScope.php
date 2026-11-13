<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Tenant fence for the uid-keyed tracking tables (spec §1.7).
 *
 * campaign_track_opens / _clicks / _unsubscribes carry no customer_id: they
 * are resolved by `campaign_uid`. Every read of them from a customer-facing
 * surface must therefore be bounded to campaigns owned by that tenant, or a
 * forged/colliding uid could surface another tenant's rows. This is the one
 * place that expression lives.
 *
 * Soft-deleted campaigns are deliberately INCLUDED: deleting a campaign must
 * not silently rewrite a subscriber's recorded history (F-2.2.5-005).
 */
final class TrackingScope
{
    /**
     * Sub-select of every campaign uid owned by one tenant, for use as the
     * second argument of `whereIn('campaign_uid', ...)`.
     */
    public static function tenantCampaignUids(int $customerId): Closure
    {
        return function (QueryBuilder $query) use ($customerId): void {
            $query->select('uid')
                ->from('campaigns')
                ->where('customer_id', $customerId);
        };
    }
}
