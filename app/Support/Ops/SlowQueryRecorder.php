<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Models\SlowQuery;
use App\Services\Settings\SettingsReader;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * §6.6 F-6.6-003 slow-query recorder (WP-24).
 *
 * `DB::listen()` fires for EVERY query, so this is opt-in: {@see register()}
 * checks the `ops.slow_query_logging` setting once at boot and installs the
 * listener only when it is on. Recording is re-entrancy guarded — the INSERT
 * this class performs must never record itself.
 */
final class SlowQueryRecorder
{
    private static bool $registered = false;

    private static bool $recording = false;

    /** Called from routes/modules/ops.php at boot (D-41/D-51 precedent). */
    public static function register(): void
    {
        if (self::$registered || ! self::enabled()) {
            return;
        }

        self::$registered = true;

        DB::listen(static function (QueryExecuted $event): void {
            self::handle($event);
        });
    }

    public static function handle(QueryExecuted $event): void
    {
        if (self::$recording) {
            return;
        }

        // The listener is installed at boot, so a mid-process settings change
        // must still take effect — otherwise switching recording off would
        // only apply after the next deploy.
        if (! self::enabled()) {
            return;
        }

        $threshold = self::thresholdMs();

        if ($event->time < $threshold) {
            return;
        }

        self::$recording = true;

        try {
            SlowQuery::query()->create([
                'fingerprint' => self::fingerprint($event->sql),
                'sql' => mb_substr($event->sql, 0, (int) config('emp-ops.ops.slow_query_max_sql_length', 4000)),
                'time_ms' => (int) round($event->time),
                'connection' => $event->connectionName,
                'context' => self::context(),
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable) {
            // Recording diagnostics must never break the request that ran the query.
        } finally {
            self::$recording = false;
        }
    }

    public static function enabled(): bool
    {
        try {
            return SettingsReader::ops()->slowQueryLogging();
        } catch (Throwable) {
            return false; // Settings not migrated yet (installer / first boot).
        }
    }

    public static function thresholdMs(): int
    {
        try {
            return SettingsReader::ops()->slowQueryThresholdMs();
        } catch (Throwable) {
            return (int) config('emp-ops.ops.slow_query_threshold_ms', 500);
        }
    }

    /** Normalised SQL shape hash so repeated queries aggregate. */
    public static function fingerprint(string $sql): string
    {
        $normalised = preg_replace('/\s+/', ' ', strtolower(trim($sql))) ?? $sql;
        $normalised = preg_replace('/\b\d+\b/', '?', $normalised) ?? $normalised;
        $normalised = preg_replace("/'[^']*'/", '?', $normalised) ?? $normalised;

        return substr(md5($normalised), 0, 32);
    }

    /** Best-effort "where did this run" label (command name or request path). */
    private static function context(): ?string
    {
        if (app()->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];

            return isset($argv[1]) ? 'artisan '.(string) $argv[1] : 'console';
        }

        $request = request();

        return $request === null ? null : mb_substr($request->method().' '.$request->path(), 0, 191);
    }

    /** Test seam — lets a test install the listener regardless of settings. */
    public static function forget(): void
    {
        self::$registered = false;
    }
}
