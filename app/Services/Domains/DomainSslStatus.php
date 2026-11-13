<?php

declare(strict_types=1);

namespace App\Services\Domains;

use App\Contracts\Domains\SslCertificateInspector;
use App\Models\Domain;
use Carbon\CarbonImmutable;

/**
 * Records the observed TLS state of a brand host (spec §6.4 F-6.4-004).
 *
 * This is the whole of "per-domain SSL handling" and the docblock says so
 * plainly: the platform REPORTS certificate state, it does not issue
 * certificates. See {@see SslCertificateInspector} for why.
 *
 * Status meanings, in the order they are decided:
 *   error     — the host could not be reached over TLS at all.
 *   none      — reachable but presenting no usable certificate.
 *   expired   — validity window is in the past.
 *   mismatch  — valid dates, but the certificate does not cover this host
 *               (wildcard included), i.e. browsers will refuse it.
 *   expiring  — valid and matching, but inside the warning window.
 *   active    — valid, matching, and not near expiry.
 */
class DomainSslStatus
{
    public function __construct(private readonly SslCertificateInspector $inspector) {}

    /**
     * Probe a domain and persist what was found. Returns the same model.
     */
    public function refresh(Domain $domain): Domain
    {
        $result = $this->inspector->inspect(
            (string) $domain->host,
            (int) config('emp-themes.domains.ssl_probe_port', 443),
            (int) config('emp-themes.domains.ssl_probe_timeout', 5),
        );

        $domain->forceFill($this->attributesFor((string) $domain->host, $result))->save();

        return $domain;
    }

    /**
     * Pure mapping from a probe result to the stored columns — separated so
     * the decision table can be tested without a socket.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function attributesFor(string $host, array $result): array
    {
        $now = CarbonImmutable::now();

        $base = [
            'ssl_checked_at' => $now,
            'ssl_issuer' => null,
            'ssl_expires_at' => null,
            'ssl_error' => null,
        ];

        // array_merge, never the `+` union operator: `+` keeps the LEFT
        // operand's key, so unioning onto $base would silently discard every
        // value computed below and report a null issuer and a null error on
        // every probe.
        if (! ($result['reachable'] ?? false)) {
            return array_merge($base, [
                'ssl_status' => Domain::SSL_ERROR,
                'ssl_error' => $this->text($result['error'] ?? null),
            ]);
        }

        $validTo = $result['valid_to'] ?? null;

        if ($validTo === null) {
            return array_merge($base, [
                'ssl_status' => Domain::SSL_NONE,
                'ssl_error' => $this->text($result['error'] ?? null),
            ]);
        }

        $expiresAt = CarbonImmutable::createFromTimestampUTC((int) $validTo);
        $issuer = $this->text($result['issuer'] ?? null);

        $common = [
            'ssl_checked_at' => $now,
            'ssl_issuer' => $issuer,
            'ssl_expires_at' => $expiresAt,
            'ssl_error' => null,
        ];

        if ($expiresAt->isPast()) {
            return array_merge($common, ['ssl_status' => Domain::SSL_EXPIRED]);
        }

        /** @var list<string> $names */
        $names = array_map('strval', (array) ($result['names'] ?? []));

        if (! $this->covers($names, $host)) {
            return array_merge($common, [
                'ssl_status' => Domain::SSL_MISMATCH,
                'ssl_error' => __('themes.domains.ssl_errors.mismatch', [
                    'names' => $names === [] ? '—' : implode(', ', $names),
                ]),
            ]);
        }

        $warnDays = max(0, (int) config('emp-themes.domains.ssl_expiry_warning_days', 14));

        if ($warnDays > 0 && $expiresAt->lessThanOrEqualTo(CarbonImmutable::now()->addDays($warnDays))) {
            return array_merge($common, ['ssl_status' => Domain::SSL_EXPIRING]);
        }

        return array_merge($common, ['ssl_status' => Domain::SSL_ACTIVE]);
    }

    /**
     * Hostname matching per RFC 6125: exact, or a single leading `*` label
     * that matches exactly one label. `*.example.com` does NOT cover
     * `example.com` or `a.b.example.com` — getting that wrong would report a
     * broken brand as healthy.
     *
     * @param  list<string>  $names
     */
    public function covers(array $names, string $host): bool
    {
        $host = mb_strtolower(trim($host));

        foreach ($names as $name) {
            $name = mb_strtolower(trim($name));

            if ($name === '' || $host === '') {
                continue;
            }

            if ($name === $host) {
                return true;
            }

            if (! str_starts_with($name, '*.')) {
                continue;
            }

            $suffix = substr($name, 1); // ".example.com"

            if (! str_ends_with($host, $suffix)) {
                continue;
            }

            $label = substr($host, 0, -strlen($suffix));

            if ($label !== '' && ! str_contains($label, '.')) {
                return true;
            }
        }

        return false;
    }

    private function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 500);
    }
}
