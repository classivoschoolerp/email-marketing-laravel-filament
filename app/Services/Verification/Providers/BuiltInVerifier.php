<?php

declare(strict_types=1);

namespace App\Services\Verification\Providers;

use App\Rules\NotDisposableEmail;
use App\Services\Dns\DnsResolverInterface;
use App\Services\Dns\NativeDnsResolver;
use App\Services\Verification\VerificationProvider;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\Validator;

/**
 * Built-in verification engine (§2.9.12 F-2.9.12-002 — Acelle "Athena EVS"
 * equivalent, D-12), shipped as a registry plugin like every external
 * provider. Checks, in order:
 *
 *   1. syntax        — Laravel `email:rfc` (egulias) → undeliverable
 *   2. MX lookup     — DnsResolverInterface (FakeDnsResolver in tests)
 *                      → undeliverable when the domain publishes no MX
 *   3. disposable    — resources/data/disposable-email-domains.txt via
 *                      NotDisposableEmail::domains() → risky
 *   4. role account  — info@/admin@/… local parts → risky
 *   5. catch-all / SMTP handshake — HONEST STUB: no outbound SMTP is
 *      attempted (not portable to Windows CI / shared hosts; most MXs
 *      tarpit port-25 probes anyway), so this step reports "skipped" in the
 *      raw payload and never changes the verdict.
 *
 * An address passing 1–4 is tagged deliverable on the strength of the
 * passed checks; the raw payload discloses exactly which checks ran.
 */
final class BuiltInVerifier implements VerificationProvider
{
    public const KEY = 'built-in';

    /** Local parts treated as role accounts (F-2.9.12-002). */
    public const ROLE_ACCOUNTS = [
        'abuse', 'admin', 'administrator', 'billing', 'contact', 'help',
        'hello', 'hostmaster', 'hr', 'info', 'it', 'jobs', 'legal', 'mail',
        'marketing', 'news', 'newsletter', 'no-reply', 'noreply', 'office',
        'postmaster', 'privacy', 'root', 'sales', 'security', 'support',
        'team', 'webmaster',
    ];

    private readonly DnsResolverInterface $dns;

    public function __construct(?DnsResolverInterface $dns = null)
    {
        $this->dns = $dns
            ?? (app()->bound(DnsResolverInterface::class) ? app(DnsResolverInterface::class) : new NativeDnsResolver);
    }

    /**
     * Is this a role address (info@, admin@, …)? The single authority for
     * role-account detection: {@see verify()} uses it, and so does the
     * F-2.2.13-004 clean-list tool, so the two can never keep divergent
     * hardcoded lists.
     */
    public static function isRoleAccount(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return false;
        }

        return in_array(explode('@', $email, 2)[0], self::ROLE_ACCOUNTS, true);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('verification.providers.built_in');
    }

    public function requiresApiKey(): bool
    {
        return false;
    }

    public function verify(string $email): VerificationResult
    {
        $email = mb_strtolower(trim($email));
        $checks = ['smtp' => 'skipped', 'catch_all' => 'skipped'];

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc']])->fails()) {
            $checks['syntax'] = 'failed';

            return VerificationResult::undeliverable('invalid_syntax', ['checks' => $checks]);
        }

        $checks['syntax'] = 'passed';

        $domain = explode('@', $email, 2)[1];

        if ($this->dns->mxRecords($domain) === []) {
            $checks['mx'] = 'failed';

            return VerificationResult::undeliverable('no_mx_records', ['checks' => $checks]);
        }

        $checks['mx'] = 'passed';

        if (in_array($domain, NotDisposableEmail::domains(), true)) {
            $checks['disposable'] = 'failed';

            return VerificationResult::risky('disposable_domain', ['checks' => $checks]);
        }

        $checks['disposable'] = 'passed';

        if (self::isRoleAccount($email)) {
            $checks['role_account'] = 'failed';

            return VerificationResult::risky('role_account', ['checks' => $checks]);
        }

        $checks['role_account'] = 'passed';

        return VerificationResult::deliverable('passed_checks', ['checks' => $checks]);
    }
}
