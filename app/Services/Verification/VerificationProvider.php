<?php

declare(strict_types=1);

namespace App\Services\Verification;

/**
 * One pluggable verification engine (spec §2.9.12, D-12). Implementations
 * register in the ProviderRegistry — external HTTP services (Emailable,
 * Kickbox, ZeroBounce, Debounce, NeverBounce, Bouncer, Localmail, Verifyre)
 * plus the shipped BuiltInVerifier. New vendors register themselves; no core
 * change required (docs/ARCHITECTURE.md architecture rule #2).
 */
interface VerificationProvider
{
    /** Registry key, e.g. "kickbox". */
    public function key(): string;

    /** Human name for pickers, e.g. "Kickbox". */
    public function name(): string;

    /** True when the provider needs an API key to operate (built-in: false). */
    public function requiresApiKey(): bool;

    /**
     * Verify one address. Must never throw on a provider error — map
     * failures to VerificationStatus::Unknown with a reason instead, so a
     * bulk run survives vendor hiccups.
     */
    public function verify(string $email): VerificationResult;
}
