<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Providers\BrandingServiceProvider;
use App\Services\Branding\BrandContext;
use Throwable;

/**
 * Stamps the signing-up brand onto a new customer (spec §6.4 F-6.4-003).
 *
 * Registered from {@see BrandingServiceProvider} rather than
 * written into the WP-02 registration service, so EVERY creation path is
 * covered — the §1.2 form, social sign-in (§6.7), and anything a later work
 * package adds — instead of only the one that happened to be edited.
 *
 * Two fields, both only ever FILLED IN, never overwritten:
 *  - `domain_id`: which brand the account belongs to, so their panel, their
 *    system emails and their public pages keep that brand even when they
 *    later reach the app on a different host.
 *  - `customer_group_id`: the brand's own group when it declares one. It
 *    replaces NULL and the INSTALL-WIDE DEFAULT group only — those are both
 *    "nobody chose", which is exactly the gap a per-brand group is for. A
 *    caller that picked some other group has made a real decision and a host
 *    must not silently override it.
 *
 * Nothing happens outside an HTTP request: a queue worker or a seeder has no
 * host, BrandContext resolves the install-wide brand, and `domain` is null.
 */
class CustomerBrandObserver
{
    public function __construct(private readonly BrandContext $brands) {}

    public function creating(Customer $customer): void
    {
        try {
            $domain = $this->brands->current()->domain;
        } catch (Throwable) {
            return; // pre-migration boot (installer)
        }

        if ($domain === null) {
            return;
        }

        if ($customer->domain_id === null) {
            $customer->domain_id = $domain->id;
        }

        if ($domain->customer_group_id !== null && $this->groupUnchosen($customer)) {
            $customer->customer_group_id = $domain->customer_group_id;
        }
    }

    /** NULL or the install-wide default both mean "nobody chose". */
    private function groupUnchosen(Customer $customer): bool
    {
        if ($customer->customer_group_id === null) {
            return true;
        }

        try {
            return $customer->customer_group_id === CustomerGroup::default()?->id;
        } catch (Throwable) {
            return false;
        }
    }
}
