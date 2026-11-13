<?php

declare(strict_types=1);

namespace App\Services\Blacklist;

use App\Models\GlobalBlacklistEmail;
use App\Services\Settings\SettingsReader;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one matching engine for the global blacklist (§3.17): exact rows,
 * domain rows (is_domain) and the BlacklistSettings regex rules, honoring
 * the shared/per-customer isolation mode (F-3.25.5-004). Both enforcement
 * points — BlacklistImportGuard (apply-at-import) and BlacklistSendGuard
 * (apply-at-send) — and the admin rule tester delegate here so the three
 * row kinds can never drift apart.
 *
 * Isolation semantics: shared mode → every row applies to everyone;
 * per-customer mode → install-wide rows (customer_id null) always apply,
 * customer-owned rows only to that customer.
 */
class BlacklistMatcher
{
    public const MATCH_EXACT = 'exact';

    public const MATCH_DOMAIN = 'domain';

    public const MATCH_REGEX = 'regex';

    public function matches(string $email, ?int $customerId = null): bool
    {
        return $this->matchType($email, $customerId) !== null;
    }

    /**
     * Which rule kind hit, or null when the address is clean (also null when
     * the blacklist feature is disabled). Used by the §3.17 rule tester.
     */
    public function matchType(string $email, ?int $customerId = null): ?string
    {
        $reader = SettingsReader::blacklist();

        if (! $reader->enabled()) {
            return null;
        }

        $email = mb_strtolower(trim($email));
        $domain = str_contains($email, '@') ? explode('@', $email, 2)[1] : null;

        $row = $this->visibleRows($customerId, $reader->sharedAcrossCustomers())
            ->where(function (Builder $query) use ($email, $domain): void {
                $query->where(fn (Builder $q) => $q->where('is_domain', false)->where('email', $email));

                if ($domain !== null) {
                    $query->orWhere(fn (Builder $q) => $q->where('is_domain', true)->where('email', $domain));
                }
            })
            ->orderByDesc('is_domain')
            ->first();

        if ($row !== null) {
            return $row->is_domain ? self::MATCH_DOMAIN : self::MATCH_EXACT;
        }

        return $reader->matchesRegexRule($email) ? self::MATCH_REGEX : null;
    }

    /** @return Builder<GlobalBlacklistEmail> */
    private function visibleRows(?int $customerId, bool $shared): Builder
    {
        $query = GlobalBlacklistEmail::query();

        if ($shared) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($customerId): void {
            $q->whereNull('customer_id');

            if ($customerId !== null) {
                $q->orWhere('customer_id', $customerId);
            }
        });
    }
}
