<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Services\Reports\LogFileReader;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Application/system error log viewer (spec §3.27 F-3.27-008, WP-17):
 * severity-filtered tail of storage/logs/laravel.log via the big-file-safe
 * reverse reader (LogFileReader) — the file is never loaded whole.
 */
class ErrorLogPage extends Page
{
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.error-log';

    protected static ?string $slug = 'logs/error-log';

    protected static ?int $navigationSort = 116;

    /** Severity filter; 'all' or a Monolog level name. */
    public string $level = 'all';

    /** How many entries to show. */
    public int $entries = 100;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.error_log.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('reports.admin.error_log.title');
    }

    /** @return list<string> */
    public function levels(): array
    {
        return LogFileReader::LEVELS;
    }

    /** @return list<array{timestamp: string, env: string, level: string, message: string, context: string}> */
    public function logEntries(): array
    {
        $reader = app(LogFileReader::class);

        return $reader->tail(
            $reader->defaultPath(),
            max(10, min(500, $this->entries)),
            $this->level === 'all' ? null : [$this->level],
        );
    }

    public function logPath(): string
    {
        return app(LogFileReader::class)->defaultPath();
    }

    public function logSize(): int
    {
        $reader = app(LogFileReader::class);

        return $reader->size($reader->defaultPath());
    }

    public function levelColor(string $level): string
    {
        return match ($level) {
            'DEBUG', 'INFO' => 'gray',
            'NOTICE', 'WARNING' => 'warning',
            default => 'danger',
        };
    }
}
