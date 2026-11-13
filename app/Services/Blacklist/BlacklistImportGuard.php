<?php

declare(strict_types=1);

namespace App\Services\Blacklist;

use App\Models\EmailList;
use App\Services\Imports\ImportGuard;
use App\Services\Settings\SettingsReader;

/**
 * The real ImportGuard (WP-16): replaces the WP-08 NullImportGuard binding
 * (see routes/modules/blacklist.php, D-41/51 wiring). Skips an address at
 * import time when the blacklist applies at import (BlacklistSettings
 * apply_at ∈ import|both) and the address matches an exact/domain/regex
 * rule visible to the importing customer (shared vs isolated semantics in
 * BlacklistMatcher). SubscriberImporter counts these as "skipped"
 * (F-2.2.7-010).
 */
final class BlacklistImportGuard implements ImportGuard
{
    public function __construct(private readonly BlacklistMatcher $matcher) {}

    public function shouldSkip(string $email, EmailList $list): bool
    {
        if (! SettingsReader::blacklist()->appliesAtImport()) {
            return false;
        }

        return $this->matcher->matches($email, $list->customer_id);
    }
}
