<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * Big-file-safe reader for the Laravel log (spec §3.27 F-3.27-008, WP-17):
 * seeks to the tail of the file (never loads it whole), parses Monolog
 * `[timestamp] env.LEVEL: message` header lines, folds continuation lines
 * (stack traces) into their entry's context and filters by severity.
 */
class LogFileReader
{
    /** Monolog line header. */
    private const HEADER = '/^\[(?<ts>\d{4}-\d{2}-\d{2}[ T][0-9:.+]+)\]\s+(?<env>[\w-]+)\.(?<level>[A-Z]+):\s?(?<message>.*)$/';

    public const LEVELS = ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public function defaultPath(): string
    {
        return storage_path('logs/laravel.log');
    }

    /**
     * Newest-first log entries from the file tail.
     *
     * @param  list<string>|null  $levels  restrict to these levels (upper-case); null = all
     * @param  int  $maxBytes  how much of the file tail to inspect (default 1 MiB)
     * @return list<array{timestamp: string, env: string, level: string, message: string, context: string}>
     */
    public function tail(string $path, int $maxEntries = 100, ?array $levels = null, int $maxBytes = 1048576): array
    {
        if (! is_file($path)) {
            return [];
        }

        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $offset = max(0, $size - $maxBytes);
            fseek($handle, $offset);
            $chunk = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        // When starting mid-file, drop the (possibly partial) first line.
        if ($offset > 0) {
            $newline = strpos($chunk, "\n");
            $chunk = $newline === false ? '' : substr($chunk, $newline + 1);
        }

        $entries = [];
        $current = null;

        foreach (explode("\n", $chunk) as $line) {
            $line = rtrim($line, "\r");

            if (preg_match(self::HEADER, $line, $matches) === 1) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'timestamp' => $matches['ts'],
                    'env' => $matches['env'],
                    'level' => $matches['level'],
                    'message' => $matches['message'],
                    'context' => '',
                ];
            } elseif ($current !== null && $line !== '') {
                // Continuation (stack trace / context dump) of the entry above.
                $current['context'] .= ($current['context'] === '' ? '' : "\n").$line;
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        if ($levels !== null) {
            $levels = array_map('strtoupper', $levels);
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => in_array($entry['level'], $levels, true),
            ));
        }

        // Newest first, capped.
        return array_slice(array_reverse($entries), 0, $maxEntries);
    }

    /** File size in bytes (0 when missing) — shown on the viewer page. */
    public function size(string $path): int
    {
        return is_file($path) ? (int) filesize($path) : 0;
    }
}
