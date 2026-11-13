<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\EmailList;

/**
 * Pre-insert veto hook for imports (spec F-2.2.7-010: "ignore blacklisted /
 * suppression-list matches automatically"). The real global-blacklist guard
 * arrives with WP-16 (§2.9.11) and registers itself by binding this
 * interface in the container; until then NullImportGuard (never skips) is
 * used. SubscriberImporter counts guarded addresses as "skipped".
 */
interface ImportGuard
{
    /** True when the address must NOT be imported (e.g. globally blacklisted). */
    public function shouldSkip(string $email, EmailList $list): bool;
}
