<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Theme;
use App\Models\User;
use App\Policies\Concerns\DeniesNonAdminActors;
use App\Support\Roles\RoleChecker;

/**
 * Theme packages + branding (spec §6.3, §3.21, §4.7) behind ONE coarse admin
 * permission, `themes.manage`, following the `plugins.manage` precedent for
 * install-wide operator data.
 *
 * Kept SEPARATE from `plugins.manage` on purpose. A theme package is
 * presentation only — config/emp-themes.php rejects any archive containing an
 * executable or scriptable file, and nothing extracted is ever included or
 * run — so delegating "may re-skin the product" must not silently delegate
 * "may execute arbitrary PHP".
 *
 * No customer-guard path: themes are install-wide. A customer's OWN theme
 * preference (F-6.3-002) is not governed by this policy at all — it is a
 * preference on their own row, like their locale.
 *
 * Super Admin passes through the global Gate::before (AppServiceProvider).
 */
class ThemePolicy
{
    use DeniesNonAdminActors;

    public const ADMIN_PERMISSION = 'themes.manage';

    public function viewAny(User $user): bool
    {
        return app(RoleChecker::class)->hasActivePermission($user, self::ADMIN_PERMISSION);
    }

    public function view(User $user, Theme $theme): bool
    {
        return $this->viewAny($user);
    }

    /** Uploading a package IS creating a theme. */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Theme $theme): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Theme $theme): bool
    {
        return $this->viewAny($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
