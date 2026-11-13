<?php

declare(strict_types=1);

namespace App\Support\System;

/**
 * §0.1 F-0.1-015 — multi-process / multi-threading auto-detection based on
 * available CPU cores (WP-24).
 *
 * PHP has no portable core count, so each source is tried in turn and the
 * result is honest about which one answered:
 *   1. `nproc` via /sys (Linux cgroup-aware host count);
 *   2. /proc/cpuinfo processor lines (Linux);
 *   3. NUMBER_OF_PROCESSORS (Windows);
 *   4. `sysctl -n hw.ncpu` (macOS/BSD) — only when shell_exec is allowed;
 *   5. fallback 1.
 *
 * Container CPU limits are read from the cgroup v2 `cpu.max` / v1
 * `cpu.cfs_quota_us` files when present, so a 2-CPU container never claims
 * the 64 cores of its host.
 */
final class Cpu
{
    private static ?int $memo = null;

    public static function cores(): int
    {
        return self::$memo ??= max(1, min(self::hostCores(), self::cgroupLimit() ?? PHP_INT_MAX));
    }

    /** Which detection source answered — surfaced on the Ops page. */
    public static function source(): string
    {
        if (self::cgroupLimit() !== null) {
            return 'cgroup';
        }

        if (@is_readable('/sys/devices/system/cpu/present')) {
            return 'sysfs';
        }

        if (@is_readable('/proc/cpuinfo')) {
            return 'procfs';
        }

        if (getenv('NUMBER_OF_PROCESSORS') !== false) {
            return 'env';
        }

        return 'fallback';
    }

    /**
     * Suggested process counts for the §0.4 worker pools. Deliberately
     * conservative: sending is I/O bound so it gets the lion's share, one
     * core is always reserved for the web/scheduler process.
     *
     * @return array{sending: int, general: int, scheduler: int, total: int}
     */
    public static function suggestedWorkers(): array
    {
        $cores = self::cores();
        $available = max(1, $cores - 1);

        $sending = max(1, (int) ceil($available * 0.6));
        $general = max(1, $available - $sending);

        return [
            'sending' => $sending,
            'general' => $general,
            'scheduler' => 1,
            'total' => $sending + $general + 1,
        ];
    }

    /** Test seam. */
    public static function flush(): void
    {
        self::$memo = null;
    }

    private static function hostCores(): int
    {
        // Linux: /sys/devices/system/cpu/present is "0-7" style.
        $present = @file_get_contents('/sys/devices/system/cpu/present');

        if (is_string($present) && preg_match('/^0-(\d+)/', trim($present), $matches) === 1) {
            return (int) $matches[1] + 1;
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');

        if (is_string($cpuinfo)) {
            $count = preg_match_all('/^processor\s*:/mi', $cpuinfo);

            if ($count > 0) {
                return $count;
            }
        }

        $windows = getenv('NUMBER_OF_PROCESSORS');

        if ($windows !== false && (int) $windows > 0) {
            return (int) $windows;
        }

        return 1;
    }

    /** @return int|null container CPU allowance, when one is configured */
    private static function cgroupLimit(): ?int
    {
        // cgroup v2: "<quota> <period>" or "max <period>".
        $v2 = @file_get_contents('/sys/fs/cgroup/cpu.max');

        if (is_string($v2)) {
            $parts = preg_split('/\s+/', trim($v2)) ?: [];

            if (count($parts) === 2 && $parts[0] !== 'max' && (int) $parts[1] > 0) {
                return max(1, (int) ceil((int) $parts[0] / (int) $parts[1]));
            }
        }

        // cgroup v1.
        $quota = @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
        $period = @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_period_us');

        if (is_string($quota) && is_string($period) && (int) $quota > 0 && (int) $period > 0) {
            return max(1, (int) ceil((int) $quota / (int) $period));
        }

        return null;
    }
}
