<?php

declare(strict_types=1);

namespace App\Services\Upgrade;

use App\Mail\Upgrade\NewReleaseAvailableMail;
use App\Models\User;
use App\Services\Ops\ResourceMonitor;
use App\Support\Roles\RoleChecker;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * §6.2 F-6.2-008 (optional) — tell operators a new release exists (WP-41).
 *
 * Recipient rule mirrors {@see ResourceMonitor}: an explicit
 * address from configuration wins, otherwise every admin holding the
 * permission that would actually let them act on the notice
 * (`system.upgrade`) plus Super Admins. Mailing an announcement to admins who
 * cannot upgrade is noise.
 *
 * Deduplicated per version by {@see ReleaseChecker::alreadyNotified()} so a
 * daily check cannot send the same announcement thirty times.
 */
class ReleaseNotifier
{
    public function __construct(private readonly ReleaseChecker $checker) {}

    public function enabled(): bool
    {
        return (bool) config('emp-upgrade.notify.enabled', false);
    }

    /**
     * @return int recipients notified (0 = disabled, already sent, or nobody
     *             is entitled to act on it)
     */
    public function notify(ReleaseInfo $release, bool $force = false): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        if (! $force && $this->checker->alreadyNotified($release->version)) {
            return 0;
        }

        $sent = 0;
        $explicit = config('emp-upgrade.notify.to');

        if (is_string($explicit) && trim($explicit) !== '') {
            $this->mail(trim($explicit), $release);
            $sent++;
        } else {
            foreach ($this->recipients() as $user) {
                $this->mail((string) $user->email, $release);
                $this->tray($user, $release);
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->checker->markNotified($release->version);
        }

        return $sent;
    }

    /** @return list<User> */
    public function recipients(): array
    {
        try {
            $checker = app(RoleChecker::class);
            $superAdmin = (string) config('emp.roles.super_admin');

            return User::query()->get()->filter(
                static fn (User $user): bool => $checker->hasActivePermission($user, UpgradePermissions::MANAGE)
                    || $checker->hasActiveRole($user, $superAdmin),
            )->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function mail(string $to, ReleaseInfo $release): void
    {
        try {
            Mail::to($to)->send(new NewReleaseAvailableMail(
                $release->toArray(),
                $this->checker->currentVersion(),
            ));
        } catch (Throwable) {
            // An announcement must never take down the scheduler.
        }
    }

    private function tray(User $user, ReleaseInfo $release): void
    {
        try {
            Notification::make()
                ->info()
                ->icon('heroicon-o-arrow-up-circle')
                ->title(__('upgrade.mail.greeting'))
                ->body(__('upgrade.mail.line_version', [
                    'version' => $release->version,
                    'current' => $this->checker->currentVersion(),
                ]))
                ->sendToDatabase($user);
        } catch (Throwable) {
            // Tray delivery is best effort (notifications table may be absent).
        }
    }
}
