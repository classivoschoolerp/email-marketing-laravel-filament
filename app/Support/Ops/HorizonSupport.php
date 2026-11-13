<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Models\User;
use App\Support\Roles\RoleChecker;
use Illuminate\Support\Facades\Gate;

/**
 * §0.1 F-0.1-005 — OPTIONAL Laravel Horizon integration (D-04).
 *
 * Horizon requires ext-pcntl/ext-posix and Redis, neither installable on the
 * Windows build machine, so the package is NOT a composer dependency. This
 * class is the guarded seam: everything Horizon needs from the application is
 * configured here, and every call is a no-op unless the package is present.
 *
 * On a Linux host, `composer require laravel/horizon` is enough — package
 * auto-discovery registers Horizon, config/horizon.php (already in the repo)
 * becomes its configuration, and {@see configure()} — called from
 * routes/modules/ops.php — puts the dashboard behind the same `logs.view`
 * permission as the built-in queue monitor.
 *
 * When Horizon is absent the platform keeps the WP-17 QueueMonitorService and
 * the WP-24 worker registry, which cover the same ground without pcntl.
 */
final class HorizonSupport
{
    public static function installed(): bool
    {
        return class_exists(\Laravel\Horizon\Horizon::class);
    }

    /** Register the dashboard authorisation gate (no-op without the package). */
    public static function configure(): void
    {
        if (! self::installed()) {
            return;
        }

        Gate::define('viewHorizon', static function (mixed $user = null): bool {
            if (! $user instanceof User) {
                return false;
            }

            $checker = app(RoleChecker::class);

            return $checker->hasActivePermission($user, 'logs.view')
                || $checker->hasActiveRole($user, (string) config('emp.roles.super_admin'));
        });

        \Laravel\Horizon\Horizon::auth(static fn ($request): bool => Gate::forUser(
            $request->user('admin'),
        )->allows('viewHorizon'));
    }

    /**
     * Status for the Ops page / docs: whether Horizon could run here at all.
     *
     * @return array{installed: bool, pcntl: bool, posix: bool, redis_queue: bool, note: string}
     */
    public static function status(): array
    {
        $pcntl = extension_loaded('pcntl');
        $posix = extension_loaded('posix');
        $redisQueue = config('queue.default') === 'redis';

        return [
            'installed' => self::installed(),
            'pcntl' => $pcntl,
            'posix' => $posix,
            'redis_queue' => $redisQueue,
            'note' => match (true) {
                self::installed() => 'Horizon is installed and active.',
                ! $pcntl || ! $posix => 'Horizon needs ext-pcntl and ext-posix, which this host does not have (D-04).',
                ! $redisQueue => 'Horizon needs a Redis queue connection; this host uses '.(string) config('queue.default').'.',
                default => 'Host is capable — run "composer require laravel/horizon" to enable it.',
            },
        ];
    }
}
