<?php

declare(strict_types=1);

namespace App\Contracts\Domains;

/**
 * Reads the TLS certificate a host actually presents (spec §6.4 F-6.4-004).
 *
 * DELIBERATELY READ-ONLY. There is no issue(), no renew(), no install().
 * Nothing in this application obtains a certificate: ACME issuance needs
 * control of port 80/443 and of the web server config, neither of which a
 * PHP application owns, and pretending otherwise would produce a UI that
 * reports success while serving a broken site.
 *
 * What §6.4 F-6.4-004 gets instead is honest reporting: connect, read the
 * peer certificate, record issuer / validity window / whether it actually
 * covers the host, and surface that to the operator with the CNAME and
 * certificate instructions they need to fix it.
 */
interface SslCertificateInspector
{
    /**
     * @return array{
     *     reachable: bool,
     *     subject: ?string,
     *     issuer: ?string,
     *     valid_from: ?int,
     *     valid_to: ?int,
     *     names: list<string>,
     *     error: ?string,
     * }
     */
    public function inspect(string $host, int $port = 443, int $timeout = 5): array;
}
