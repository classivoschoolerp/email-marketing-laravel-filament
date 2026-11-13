<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\RequiresLogsPermission;
use App\Models\SlowQuery;
use App\Services\Ops\IndexHealthChecker;
use App\Services\Ops\PerformanceMonitor;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * §6.6 F-6.6-003 performance monitoring panel + F-6.6-004 index health check.
 *
 * Slow queries are recorded only while the Ops setting is on (the DB::listen
 * hook is not even installed otherwise); queue depth reuses WP-17; worker
 * count comes from the F-6.6-008 registry; cache hit rate is reported only
 * for drivers that expose the counters. The index check reports honestly that
 * unused-index detection requires MySQL + performance_schema (D-03).
 */
class PerformancePage extends Page
{
    use RequiresLogsPermission;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.performance';

    protected static ?string $slug = 'ops/performance';

    protected static ?int $navigationSort = 111;

    public static function getNavigationGroup(): ?string
    {
        return __('reports.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.performance.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('ops.performance.title');
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return app(PerformanceMonitor::class)->summary();
    }

    /** @return list<array<string, mixed>> */
    public function topSlowQueries(): array
    {
        return app(PerformanceMonitor::class)->topSlowQueries();
    }

    /** @return array<string, mixed> */
    public function indexReport(): array
    {
        return app(IndexHealthChecker::class)->report();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('clearSlowQueries')
                ->label(__('ops.performance.clear_slow_queries'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): void {
                    $deleted = SlowQuery::query()->delete();

                    Notification::make()
                        ->success()
                        ->title(__('ops.performance.cleared', ['count' => $deleted]))
                        ->send();
                }),
        ];
    }
}
