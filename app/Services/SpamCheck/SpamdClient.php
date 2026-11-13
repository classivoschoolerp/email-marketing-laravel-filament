<?php

declare(strict_types=1);

namespace App\Services\SpamCheck;

/**
 * Socket seam for the spamd wire protocol (docs/DECISIONS.md D-08). The checker
 * builds the SPAMC request framing; implementations only move bytes. Tests
 * use FakeSpamdClient with captured transcripts; production uses
 * TcpSpamdClient against a real daemon.
 */
interface SpamdClient
{
    /**
     * Send one SPAMC request (headers + body) and return the complete raw
     * SPAMD response (status line, headers, body).
     *
     * @throws \RuntimeException when the daemon is unreachable
     */
    public function exchange(string $request): string;
}
