<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * File scanning contract (spec §3.25.15 F-3.25.15-006, WP-22).
 *
 * Follows the platform-wide driver-registry convention (docs/ARCHITECTURE.md §2): a new
 * anti-virus vendor is a new implementation of this interface plus a key in
 * the `upload_scanner_driver` setting — never a core change.
 */
interface VirusScanner
{
    /** Stable driver key matching the `upload_scanner_driver` setting. */
    public function key(): string;

    /**
     * Scan a file on the local filesystem.
     *
     * Implementations must never throw for operational problems (daemon
     * down, socket refused): they return a clean result flagged
     * `scanned: false` so an unreachable scanner cannot block business
     * operations — the same fail-open contract as the SpamAssassin gate
     * (D-08/D-49).
     */
    public function scan(string $absolutePath): ScanResult;
}
