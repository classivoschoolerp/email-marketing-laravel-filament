<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Customer;
use App\Support\Api\IpMatcher;
use Illuminate\Validation\ValidationException;

/**
 * Per-account IP allowlist (spec §2.15.2 F-2.15.2-004).
 *
 * The single authority for "may this address reach this account?", so the
 * login page, the panel middleware and the settings form cannot disagree.
 *
 * Two rules are load-bearing:
 *
 *  1. **Empty means unrestricted.** A customer who never configured this is
 *     never affected, and a customer who clears the list has switched the
 *     feature off. There is no second boolean to fall out of sync with.
 *
 *  2. **A list that would lock the author out is refused at save time.**
 *     This is the difference between a security control and a support
 *     ticket: the one thing an IP allowlist reliably does is exclude the
 *     person who typed it. {@see assertWouldNotLockOut()} is called from the
 *     form, not from the enforcement path — enforcement must never be
 *     softened by a "but they might lock themselves out" exception.
 *
 * Matching reuses {@see IpMatcher} — single addresses and CIDR ranges, IPv4
 * and IPv6 — so account rules behave exactly like API-key rules. The
 * §3.25.15 admin allowlist is a different control with a different owner;
 * this one does not touch it.
 */
class AccountIpAllowlist
{
    /** Guard against a pathological list making every request O(entries). */
    public const MAX_ENTRIES = 50;

    /** @return list<string> */
    public function entriesFor(Customer $customer): array
    {
        $entries = $customer->ip_allowlist;

        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $entries,
        ), static fn (string $entry): bool => $entry !== ''));
    }

    public function restricted(Customer $customer): bool
    {
        return $this->entriesFor($customer) !== [];
    }

    /**
     * May `$ip` reach this account?
     *
     * A null/blank IP FAILS a configured allowlist. A request whose origin
     * cannot be determined is exactly the request an allowlist exists to
     * refuse, and failing open here would make the control decorative.
     */
    public function allows(Customer $customer, ?string $ip): bool
    {
        $entries = $this->entriesFor($customer);

        if ($entries === []) {
            return true;
        }

        $ip = trim((string) $ip);

        if ($ip === '') {
            return false;
        }

        return IpMatcher::matches($ip, $entries);
    }

    /**
     * Validate + de-duplicate a submitted list.
     *
     * @param  list<string>|null  $entries
     * @return list<string>
     *
     * @throws ValidationException
     */
    public function normalize(?array $entries, string $attribute = 'ipAllowlist'): array
    {
        $clean = [];

        foreach ($entries ?? [] as $entry) {
            $entry = trim((string) $entry);

            if ($entry === '') {
                continue;
            }

            if (! $this->isValidEntry($entry)) {
                throw ValidationException::withMessages([
                    $attribute => __('auth-module.ip_allowlist.errors.invalid', ['entry' => $entry]),
                ]);
            }

            $clean[$entry] = $entry;
        }

        $clean = array_values($clean);

        if (count($clean) > self::MAX_ENTRIES) {
            throw ValidationException::withMessages([
                $attribute => __('auth-module.ip_allowlist.errors.too_many', ['max' => self::MAX_ENTRIES]),
            ]);
        }

        return $clean;
    }

    /**
     * Refuse a list the author's own address does not satisfy.
     *
     * @param  list<string>  $entries
     *
     * @throws ValidationException
     */
    public function assertWouldNotLockOut(array $entries, ?string $currentIp, string $attribute = 'ipAllowlist'): void
    {
        if ($entries === []) {
            return;
        }

        $currentIp = trim((string) $currentIp);

        if ($currentIp !== '' && IpMatcher::matches($currentIp, $entries)) {
            return;
        }

        throw ValidationException::withMessages([
            $attribute => __('auth-module.ip_allowlist.errors.lockout', [
                'ip' => $currentIp === '' ? '—' : $currentIp,
            ]),
        ]);
    }

    /** A single address, or an address with a CIDR prefix length. */
    private function isValidEntry(string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $bits] = explode('/', $entry, 2);

        if (filter_var(trim($address), FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (! ctype_digit($bits)) {
            return false;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return (int) $bits >= 0 && (int) $bits <= $max;
    }
}
