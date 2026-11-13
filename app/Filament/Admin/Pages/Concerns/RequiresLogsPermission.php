<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\User;
use App\Support\Roles\RoleChecker;

/**
 * Access gate for the §3.27 admin monitoring pages (WP-17): admin-guard
 * users with the `logs.view` permission or the Super Admin role. Customers
 * can never reach these pages — the admin panel authenticates the admin
 * guard, and this check requires a User on top (defense in depth).
 */
trait RequiresLogsPermission
{
    public static function canAccess(): bool
    {
        $user = auth('admin')->user();

        if (! $user instanceof User) {
            return false;
        }

        $checker = app(RoleChecker::class);

        return $checker->hasActivePermission($user, 'logs.view')
            || $checker->hasActiveRole($user, (string) config('emp.roles.super_admin'));
    }
}
