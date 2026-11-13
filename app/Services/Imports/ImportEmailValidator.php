<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\VerificationStatus;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Dns\NativeDnsResolver;
use App\Services\Verification\Providers\BuiltInVerifier;

/**
 * Optional syntax + MX validation during an import run (spec §2.2.7
 * F-2.2.7-014, import option `validate_emails`).
 *
 * This does NOT add a second email checker. It runs the shipped built-in
 * verification engine (D-12 — `BuiltInVerifier`, which already does syntax →
 * MX → disposable → role account) over a {@see MemoizedDnsResolver}, so one
 * queued chunk resolves each domain once instead of once per row.
 *
 * Verdict handling, stated plainly:
 *   - `undeliverable` (invalid syntax, or the domain publishes no MX) →
 *     the row FAILS: it is counted in failed_count and written to the
 *     downloadable error CSV with the engine's reason.
 *   - `risky` (disposable domain, role account) → the row is still imported.
 *     The spec row asks for syntax + MX validation; refusing role accounts
 *     silently would drop addresses the operator never asked to drop. The
 *     dedicated §2.9.12 verification run is where risky verdicts get acted
 *     on.
 */
class ImportEmailValidator
{
    private readonly MemoizedDnsResolver $dns;

    private readonly BuiltInVerifier $verifier;

    public function __construct(?DnsResolverInterface $dns = null)
    {
        $inner = $dns
            ?? (app()->bound(DnsResolverInterface::class) ? app(DnsResolverInterface::class) : new NativeDnsResolver);

        $this->dns = new MemoizedDnsResolver($inner);
        $this->verifier = new BuiltInVerifier($this->dns);
    }

    /**
     * Translated failure reason for an address that must not be imported, or
     * null when the address passed syntax + MX.
     */
    public function reject(string $email): ?string
    {
        $result = $this->verifier->verify($email);

        if ($result->status !== VerificationStatus::Undeliverable) {
            return null;
        }

        return match ($result->reason) {
            'no_mx_records' => __('imports.errors.no_mx_records', ['email' => $email]),
            default => __('imports.errors.invalid_email', ['email' => $email]),
        };
    }

    /** Distinct domains this run resolved — the per-run MX cache proof. */
    public function resolvedDomainCount(): int
    {
        return $this->dns->resolvedDomainCount();
    }
}
