<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * Default scanner (spec §3.25.15 F-3.25.15-006): reaches no verdict, so the
 * extension/mime/size checks in {@see UploadGuard} are the whole policy on
 * an installation without an anti-virus daemon.
 */
final class NullVirusScanner implements VirusScanner
{
    public function key(): string
    {
        return 'null';
    }

    public function scan(string $absolutePath): ScanResult
    {
        return ScanResult::skipped();
    }
}
