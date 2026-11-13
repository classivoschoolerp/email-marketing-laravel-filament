<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\User;
use App\Services\Reports\LogFileReader;
use App\Services\Settings\SettingsReader;
use App\Support\Roles\RoleChecker;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * §6.6 F-6.6-002 — system error notifications surfaced INSIDE the admin area.
 *
 * Poll-based by design: a log-handler hook would fire inside the failing
 * request (and inside queue workers on other nodes), so the scan runs from
 * `ops:scan-errors` on the scheduler and from the Ops dashboard's poll.
 *
 * The scan reads the tail of the Laravel log with the WP-17 {@see LogFileReader}
 * (never loads the file whole), keeps only entries at or above the configured
 * severity, and remembers a watermark in the cache so the same entry is never
 * announced twice. New entries become Filament DATABASE notifications for
 * every admin who may read logs.
 */
class ErrorLogWatcher
{
    public const CACHE_KEY = 'ops:errors:watermark';

    /** Severity order used for the "at or above" comparison. */
    private const ORDER = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function __construct(private readonly LogFileReader $reader) {}

    /** @return list<string> levels at or above the configured minimum */
    public function levels(): array
    {
        $minimum = $this->minimumLevel();
        $index = array_search($minimum, self::ORDER, true);

        if ($index === false) {
            return (array) config('emp-ops.ops.error_levels', ['ERROR']);
        }

        return array_values(array_slice(self::ORDER, (int) $index));
    }

    public function minimumLevel(): string
    {
        try {
            return SettingsReader::ops()->errorNotificationLevel();
        } catch (Throwable) {
            return 'ERROR';
        }
    }

    public function enabled(): bool
    {
        try {
            return SettingsReader::ops()->errorNotificationsEnabled();
        } catch (Throwable) {
            return true;
        }
    }

    public function path(): string
    {
        return $this->reader->defaultPath();
    }

    /**
     * Recent matching entries (newest first) — used by the errors widget.
     *
     * @return list<array{timestamp: string, env: string, level: string, message: string, context: string}>
     */
    public function recent(int $limit = 20, ?string $path = null): array
    {
        return $this->reader->tail($path ?? $this->path(), $limit, $this->levels());
    }

    /**
     * Entries that appeared since the last scan.
     *
     * @return list<array{timestamp: string, env: string, level: string, message: string, context: string}>
     */
    public function newEntries(?string $path = null): array
    {
        $entries = $this->reader->tail(
            $path ?? $this->path(),
            (int) config('emp-ops.ops.error_scan_entries', 200),
            $this->levels(),
        );

        $watermark = Cache::get(self::CACHE_KEY);
        $fresh = [];

        foreach ($entries as $entry) {
            if ($watermark !== null && $this->signature($entry) === $watermark) {
                break; // Entries are newest-first: everything below was seen.
            }

            $fresh[] = $entry;
        }

        return $fresh;
    }

    /**
     * Scan + notify. Returns the number of new entries announced.
     */
    public function scan(?string $path = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $entries = $this->newEntries($path);

        if ($entries === []) {
            return 0;
        }

        $this->markSeen($entries[0]);
        $this->notify($entries);

        return count($entries);
    }

    /** Move the watermark without notifying (used when a page is opened). */
    public function markSeen(array $entry): void
    {
        Cache::put(self::CACHE_KEY, $this->signature($entry), now()->addDays(30));
    }

    /** @param list<array<string, string>> $entries */
    private function notify(array $entries): void
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        $first = $entries[0];
        $extra = count($entries) - 1;

        $body = $extra > 0
            ? __('ops.errors.notification_body_many', [
                'message' => mb_substr($first['message'], 0, 160),
                'count' => $extra,
            ])
            : mb_substr($first['message'], 0, 200);

        $notification = Notification::make()
            ->danger()
            ->icon('heroicon-o-bug-ant')
            ->title(__('ops.errors.notification_title', ['level' => $first['level']]))
            ->body($body)
            ->actions([
                Action::make('view')
                    ->label(__('ops.errors.notification_action'))
                    ->url(url('/admin/logs/error-log')),
            ]);

        foreach ($recipients as $user) {
            $notification->sendToDatabase($user);
        }
    }

    /** @return list<User> admins allowed to read logs */
    private function recipients(): array
    {
        $checker = app(RoleChecker::class);
        $superAdmin = (string) config('emp.roles.super_admin');

        return User::query()->get()->filter(
            static fn (User $user): bool => $checker->hasActivePermission($user, 'logs.view')
                || $checker->hasActiveRole($user, $superAdmin),
        )->values()->all();
    }

    /** @param array<string, string> $entry */
    private function signature(array $entry): string
    {
        return md5($entry['timestamp'].'|'.$entry['level'].'|'.$entry['message']);
    }
}
