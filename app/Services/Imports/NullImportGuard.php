<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\EmailList;

/**
 * Default ImportGuard until WP-16 ships the global suppression list
 * (§2.9.11): nothing is ever skipped. See ImportGuard for the contract.
 */
final class NullImportGuard implements ImportGuard
{
    public function shouldSkip(string $email, EmailList $list): bool
    {
        return false;
    }
}
