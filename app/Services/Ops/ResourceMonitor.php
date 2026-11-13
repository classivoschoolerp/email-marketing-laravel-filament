<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\User;
use App\Services\Reports\SystemInfoService;
use App\Services\Settings\SettingsReader;
use App\Support\Roles\RoleChecker;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * §6.6 F-6.6-005 — disk / memory / database-size widgets with threshold
 * alerts (WP-24). Reuses the WP-17 {@see SystemInfoService} for the raw
 * numbers and adds thresholds (from the Ops settings group), a status per
 * metric, and the alert channel: an in-admin notification plus an optional
 * email, deduplicated per metric per day so a full disk cannot spam the tray.
 */
class ResourceMonitor
{
    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_UNKNOWN = 'unknown';

    public function __construct(private readonly SystemInfoService $info) {}

    /**
     * @return list<array{key: string, status: string, value: ?float, threshold: float, display: string, detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->diskCheck(),
            $this->memoryCheck(),
            $this->databaseCheck(),
        ];
    }

    /** @return array{key: string, status: string, value: ?float, threshold: float, display: string, detail: string} */
    public function diskCheck(): array
    {
        $space = $this->info->diskSpace();
        $threshold = (float) $this->setting('diskFreePercentWarning', 'disk_free_percent_warning');

        if ($space['free'] === null || $space['total'] === null || $space['total'] === 0) {
            return $this->unknown('disk', $threshold, __('ops.health.disk_unavailable'));
        }

        $percentFree = $space['free'] / $space['total'] * 100;

        return [
            'key' => 'disk',
            'status' => $percentFree < $threshold ? self::STATUS_WARNING : self::STATUS_OK,
            'value' => round($percentFree, 1),
            'threshold' => $threshold,
            'display' => round($percentFree, 1).'%',
            'detail' => __('ops.health.disk_detail', [
                'free' => $this->info->formatBytes($space['free']),
                'total' => $this->info->formatBytes($space['total']),
            ]),
        ];
    }

    /**
     * System memory when /proc/meminfo is readable (Linux hosts), otherwise
     * the PHP process' own usage against memory_limit — labelled honestly so
     * nobody reads a per-process number as a host metric.
     *
     * @return array{key: string, status: string, value: ?float, threshold: float, display: string, detail: string}
     */
    public function memoryCheck(): array
    {
        $threshold = (float) $this->setting('memoryPercentWarning', 'memory_percent_warning');
        $system = $this->systemMemory();

        if ($system !== null) {
            $used = $system['total'] - $system['available'];
            $percent = $system['total'] > 0 ? $used / $system['total'] * 100 : 0.0;

            return [
                'key' => 'memory',
                'status' => $percent > $threshold ? self::STATUS_WARNING : self::STATUS_OK,
                'value' => round($percent, 1),
                'threshold' => $threshold,
                'display' => round($percent, 1).'%',
                'detail' => __('ops.health.memory_system_detail', [
                    'used' => $this->info->formatBytes((int) $used),
                    'total' => $this->info->formatBytes((int) $system['total']),
                ]),
            ];
        }

        $limit = $this->memoryLimitBytes();
        $used = memory_get_peak_usage(true);

        if ($limit === null) {
            return $this->unknown('memory', $threshold, __('ops.health.memory_unlimited', [
                'used' => $this->info->formatBytes($used),
            ]));
        }

        $percent = $limit > 0 ? $used / $limit * 100 : 0.0;

        return [
            'key' => 'memory',
            'status' => $percent > $threshold ? self::STATUS_WARNING : self::STATUS_OK,
            'value' => round($percent, 1),
            'threshold' => $threshold,
            'display' => round($percent, 1).'%',
            'detail' => __('ops.health.memory_process_detail', [
                'used' => $this->info->formatBytes($used),
                'limit' => $this->info->formatBytes($limit),
            ]),
        ];
    }

    /** @return array{key: string, status: string, value: ?float, threshold: float, display: string, detail: string} */
    public function databaseCheck(): array
    {
        $threshold = (float) $this->setting('databaseSizeMbWarning', 'database_size_mb_warning');
        $bytes = $this->info->databaseSizeBytes();

        if ($bytes === null) {
            return $this->unknown('database', $threshold, __('ops.health.database_unavailable'));
        }

        $megabytes = $bytes / 1048576;

        return [
            'key' => 'database',
            'status' => $megabytes > $threshold ? self::STATUS_WARNING : self::STATUS_OK,
            'value' => round($megabytes, 1),
            'threshold' => $threshold,
            'display' => $this->info->formatBytes($bytes),
            'detail' => __('ops.health.database_detail', ['threshold' => (int) $threshold]),
        ];
    }

    /** @return list<array<string, mixed>> checks currently breaching a threshold */
    public function breaches(): array
    {
        return array_values(array_filter(
            $this->checks(),
            static fn (array $check): bool => $check['status'] === self::STATUS_WARNING,
        ));
    }

    /**
     * Raise the alerts (admin notification + optional email), deduplicated
     * per metric per calendar day.
     *
     * @return list<string> metric keys that raised an alert this run
     */
    public function alert(): array
    {
        $raised = [];

        foreach ($this->breaches() as $check) {
            $key = 'ops:threshold:'.$check['key'].':'.now()->toDateString();

            if (Cache::get($key) !== null) {
                continue;
            }

            Cache::put($key, true, now()->addDay());
            $this->sendNotification($check);
            $this->sendEmail($check);

            $raised[] = $check['key'];
        }

        return $raised;
    }

    /** @param array<string, mixed> $check */
    private function sendNotification(array $check): void
    {
        $notification = Notification::make()
            ->warning()
            ->icon('heroicon-o-exclamation-triangle')
            ->title(__('ops.health.alert_title', ['metric' => __('ops.health.metrics.'.$check['key'])]))
            ->body(__('ops.health.alert_body', [
                'value' => $check['display'],
                'threshold' => $check['threshold'],
            ]));

        foreach ($this->recipients() as $user) {
            $notification->sendToDatabase($user);
        }
    }

    /** @param array<string, mixed> $check */
    private function sendEmail(array $check): void
    {
        try {
            if (! SettingsReader::ops()->thresholdEmailEnabled()) {
                return;
            }

            $to = SettingsReader::ops()->thresholdEmailTo();
        } catch (Throwable) {
            return;
        }

        if ($to === null || trim($to) === '') {
            return;
        }

        Mail::to($to)->send(new \App\Mail\Ops\ThresholdAlertMail($check));
    }

    /** @return list<User> */
    private function recipients(): array
    {
        $checker = app(RoleChecker::class);
        $superAdmin = (string) config('emp.roles.super_admin');

        return User::query()->get()->filter(
            static fn (User $user): bool => $checker->hasActivePermission($user, 'logs.view')
                || $checker->hasActiveRole($user, $superAdmin),
        )->values()->all();
    }

    /** @return array{total: float, available: float}|null */
    private function systemMemory(): ?array
    {
        if (! @is_readable('/proc/meminfo')) {
            return null;
        }

        $contents = @file_get_contents('/proc/meminfo');

        if ($contents === false) {
            return null;
        }

        $values = [];

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB/', $line, $matches) === 1) {
                $values[$matches[1]] = (float) $matches[2] * 1024;
            }
        }

        if (! isset($values['MemTotal'], $values['MemAvailable'])) {
            return null;
        }

        return ['total' => $values['MemTotal'], 'available' => $values['MemAvailable']];
    }

    public function memoryLimitBytes(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return null;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** @return array{key: string, status: string, value: null, threshold: float, display: string, detail: string} */
    private function unknown(string $key, float $threshold, string $detail): array
    {
        return [
            'key' => $key,
            'status' => self::STATUS_UNKNOWN,
            'value' => null,
            'threshold' => $threshold,
            'display' => '—',
            'detail' => $detail,
        ];
    }

    private function setting(string $method, string $configKey): int
    {
        try {
            return (int) SettingsReader::ops()->{$method}();
        } catch (Throwable) {
            return (int) config('emp-ops.ops.'.$configKey, 0);
        }
    }
}
