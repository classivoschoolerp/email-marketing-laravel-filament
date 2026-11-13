<?php

declare(strict_types=1);

namespace App\Services\Domains;

/**
 * Parses a DMARC TXT record into its tag map (RFC 7489 §6.3).
 *
 * Shared by SendingDomainVerifier (record present at all → dmarc_verified,
 * unchanged WP-15 semantics) and the WP-46 BIMI checks, which need something
 * stricter: BIMI is only honoured when DMARC is at ENFORCEMENT
 * (p=quarantine or p=reject) — a p=none record publishes a policy that asks
 * receivers to do nothing, and no mailbox provider will render a logo for it.
 *
 * Stateless and DNS-free: callers hand it the TXT strings they resolved, so
 * it can be reused by any resolver seam without a second lookup.
 */
class DmarcPolicyReader
{
    /**
     * Tag map of a DMARC record, or null when the string is not one.
     *
     * @return array<string, string>|null
     */
    public function parse(string $record): ?array
    {
        $record = trim($record);

        if (stripos($record, 'v=DMARC1') !== 0) {
            return null;
        }

        $tags = [];

        foreach (explode(';', $record) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            $equals = strpos($pair, '=');

            if ($equals === false) {
                continue;
            }

            $name = strtolower(trim(substr($pair, 0, $equals)));
            $value = trim(substr($pair, $equals + 1));

            if ($name !== '') {
                $tags[$name] ??= $value;
            }
        }

        return $tags;
    }

    /**
     * First parsable DMARC record in a TXT answer set.
     *
     * @param  list<string>  $txt
     * @return array<string, string>|null
     */
    public function fromTxt(array $txt): ?array
    {
        foreach ($txt as $record) {
            $tags = $this->parse($record);

            if ($tags !== null) {
                return $tags;
            }
        }

        return null;
    }

    /** Requested policy, lower-cased ('none' when the p= tag is absent). */
    public function policy(?array $tags): ?string
    {
        if ($tags === null) {
            return null;
        }

        return strtolower(trim((string) ($tags['p'] ?? 'none')));
    }

    /** True only for p=quarantine / p=reject — what BIMI requires. */
    public function isEnforcing(?array $tags): bool
    {
        return in_array($this->policy($tags), ['quarantine', 'reject'], true);
    }

    /**
     * Percentage of mail the policy applies to (RFC 7489 pct=, default 100).
     * BIMI requires the policy to cover all mail, so pct<100 is a finding.
     */
    public function percentage(?array $tags): int
    {
        if ($tags === null || ! isset($tags['pct'])) {
            return 100;
        }

        $pct = (int) $tags['pct'];

        return $pct < 0 ? 0 : min($pct, 100);
    }

    /** Subdomain policy when one is declared (sp=), else null. */
    public function subdomainPolicy(?array $tags): ?string
    {
        if ($tags === null || ! isset($tags['sp'])) {
            return null;
        }

        return strtolower(trim((string) $tags['sp']));
    }
}
