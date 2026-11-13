<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Access gate for the WP-33 delivery-infrastructure pages (server health,
 * deliverability centre): admin-guard users with `servers.manage` or the
 * Super Admin role. Requiring a User on top of the guard keeps a
 * customer-guard session structurally unable to reach them.
 */
trait RequiresServerPermission
{
    public const PERMISSION = 'servers.manage';

    public static function canAccess(): bool
    {
        $user = auth('admin')->user();

        if (! $user instanceof User) {
            return false;
        }

        $checker = app(RoleChecker::class);

        return $checker->hasActivePermission($user, self::PERMISSION)
            || $checker->hasActiveRole($user, (string) config('emp.roles.super_admin'));
    }
}
