<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Models\User;
use App\Support\Admin\AdminModules;
use App\Support\Roles\RoleChecker;

/**
 * The trust boundary for §6.2 (WP-41).
 *
 * `system.upgrade` follows the `plugins.manage` precedent
 * ({@see AdminModules::EXTRA_PERMISSIONS}): a single,
 * coarse, deliberately un-split key. There is no "may view upgrades but not
 * apply them" variant, for the same reason WP-36 refused one — a split would
 * imply a safety that does not exist. Holding this permission means being
 * able to replace the running application's source code and migrate the live
 * database, which is total control of the installation.
 *
 * It is a SEPARATE key from `plugins.manage` because the two grant different
 * things: plugins.manage runs third-party code inside the app, system.upgrade
 * replaces the app itself. Neither implies the other.
 */
final class UpgradePermissions
{
    public const MANAGE = 'system.upgrade';

    public static function allows(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        $checker = app(RoleChecker::class);

        return $checker->hasActivePermission($user, self::MANAGE)
            || $checker->hasActiveRole($user, (string) config('emp.roles.super_admin'));
    }
}
