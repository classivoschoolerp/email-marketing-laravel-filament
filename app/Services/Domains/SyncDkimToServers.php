<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Models\DeliveryServer;
use App\Models\SendingDomain;

/**
 * DKIM signing-key handoff (spec §2.9.7 F-2.9.7-007, WP-15 documented
 * decision): MimeBuilder (WP-14, read-only here) signs with the DELIVERY
 * SERVER's dkim_* columns, so linking a server to a sending domain copies
 * the domain's selector + private key onto the server and enables signing.
 * Called when an admin saves a server with a sending_domain_id and by the
 * "sync DKIM to linked servers" action on the domain resources — after that,
 * every campaign sent through the server is signed for the domain.
 */
class SyncDkimToServers
{
    /** Copy the domain's DKIM material onto one server. */
    public function applyTo(DeliveryServer $server, SendingDomain $domain): void
    {
        $server->forceFill([
            'sending_domain_id' => $domain->id,
            'dkim_signing_enabled' => true,
            'dkim_selector' => $domain->dkim_selector,
            'dkim_private_key' => $domain->dkim_private_key,
        ])->save();
    }

    /** Push the domain's DKIM material to every linked server. @return int servers updated */
    public function syncLinkedServers(SendingDomain $domain): int
    {
        $updated = 0;

        foreach (DeliveryServer::withoutTenancy()->where('sending_domain_id', $domain->id)->get() as $server) {
            $this->applyTo($server, $domain);
            $updated++;
        }

        return $updated;
    }
}
