<?php

declare(strict_types=1);

namespace App\Services\Subscribers;

use App\Enums\SubscriberStatus;
use App\Models\GlobalBlacklistEmail;
use App\Models\Subscriber;
use App\Services\Blacklist\BlacklistEntries;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * §3.8 subscribers overview — the cross-tenant operations behind the admin
 * screen (WP-39).
 *
 * F-3.8-001 search by email, F-3.8-002 every list an address is on
 * system-wide, F-3.8-003 global unsubscribe (optionally blacklisting).
 *
 * Every query here is deliberately cross-tenant (`withoutTenancy()`), which
 * is why the whole class is only ever reachable from the admin panel behind
 * SubscriberPolicy. Emails are lower-cased on the way in because that is
 * how Subscriber stores them.
 */
class GlobalSubscriberSearch
{
    public function __construct(private readonly BlacklistEntries $blacklist) {}

    /**
     * F-3.8-001 — matching subscriber rows across every tenant. A bare
     * address returns its rows on every list; a partial string does a
     * prefix/contains match so an operator can hunt a domain.
     *
     * @return Collection<int, Subscriber>
     */
    public function search(string $term, int $limit = 200): Collection
    {
        $term = mb_strtolower(trim($term));

        if ($term === '') {
            return new Collection;
        }

        return Subscriber::withoutTenancy()
            ->with(['customer', 'list'])
            ->where('email', 'like', '%'.$term.'%')
            ->orderBy('email')
            ->limit($limit)
            ->get();
    }

    /**
     * F-3.8-002 — every list membership of one exact address, across all
     * customers, with the owning tenant.
     *
     * @return Collection<int, Subscriber>
     */
    public function memberships(string $email): Collection
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return new Collection;
        }

        return Subscriber::withoutTenancy()
            ->withTrashed()
            ->with(['customer', 'list'])
            ->where('email', $email)
            ->orderBy('customer_id')
            ->orderBy('list_id')
            ->get();
    }

    /** How many lists system-wide carry this address (live rows only). */
    public function listCount(string $email): int
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return 0;
        }

        return Subscriber::withoutTenancy()
            ->where('email', $email)
            ->distinct()
            ->count('list_id');
    }

    /**
     * F-3.8-003 — unsubscribe an address from every list on the install,
     * optionally adding it to the global blacklist so it can never be
     * re-imported.
     *
     * Rows are updated in bulk (no model events): a mass unsubscribe of one
     * address across thousands of tenants must not fan out into per-row
     * list-action processing, and the state change here is the operator's
     * decision, not a subscriber-initiated one.
     *
     * Already-unsubscribed and blacklisted rows are left alone so the
     * returned count is the number of memberships this action actually
     * changed.
     *
     * @return array{unsubscribed: int, blacklisted: int}
     */
    public function globalUnsubscribe(string $email, bool $blacklist = false, ?string $reason = null): array
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return ['unsubscribed' => 0, 'blacklisted' => 0];
        }

        $unsubscribed = DB::transaction(function () use ($email): int {
            return Subscriber::withoutTenancy()
                ->where('email', $email)
                ->whereNotIn('status', [
                    SubscriberStatus::Unsubscribed->value,
                    SubscriberStatus::Blacklisted->value,
                ])
                ->update([
                    'status' => SubscriberStatus::Unsubscribed->value,
                    'unsubscribed_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        $blacklisted = 0;

        if ($blacklist) {
            // customerId null = install-wide entry, which binds every tenant
            // in both isolation modes (D-74).
            $blacklisted = $this->blacklist->addMany(
                [$email],
                $reason,
                GlobalBlacklistEmail::SOURCE_MANUAL,
                null,
            );
        }

        return ['unsubscribed' => $unsubscribed, 'blacklisted' => $blacklisted];
    }
}
