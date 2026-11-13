<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Settings;

use App\Models\CronRun;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Cron run history (spec §3.25.11, F-3.25.11-003): read-only table of job
 * executions with duration, processed counts and errors.
 */
class CronRunHistoryPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationGroup = 'System Health';

    protected static string $view = 'filament.admin.pages.settings.cron-run-history';

    protected static ?string $slug = 'settings/cron-history';

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return __('settings.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.cron_history.navigation_label');
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.cron_history.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CronRun::query())
            ->columns([
                TextColumn::make('job')
                    ->label(__('settings.cron_history.columns.job'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('settings.cron_history.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('settings.cron_history.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        CronRun::STATUS_SUCCESS => 'success',
                        CronRun::STATUS_FAILED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('started_at')
                    ->label(__('settings.cron_history.columns.started_at'))
                    ->dateTime()
                    ->sortable(),
                // End timestamp (spec §3.27 F-3.27-007, WP-17 surgical add).
                TextColumn::make('finished_at')
                    ->label(__('settings.cron_history.columns.finished_at'))
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('duration_ms')
                    ->label(__('settings.cron_history.columns.duration'))
                    ->formatStateUsing(fn (int $state): string => $state >= 1000
                        ? number_format($state / 1000, 2).' s'
                        : $state.' ms')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('processed')
                    ->label(__('settings.cron_history.columns.processed'))
                    ->numeric()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('error')
                    ->label(__('settings.cron_history.columns.error'))
                    ->limit(60)
                    ->tooltip(fn (CronRun $record): ?string => $record->error)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('settings.cron_history.columns.status'))
                    ->options([
                        CronRun::STATUS_RUNNING => __('settings.cron_history.statuses.running'),
                        CronRun::STATUS_SUCCESS => __('settings.cron_history.statuses.success'),
                        CronRun::STATUS_FAILED => __('settings.cron_history.statuses.failed'),
                    ]),
                SelectFilter::make('job')
                    ->label(__('settings.cron_history.columns.job'))
                    ->options(fn (): array => CronRun::query()
                        ->distinct()
                        ->orderBy('job')
                        ->pluck('job', 'job')
                        ->all()),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([10, 25, 50]);
    }
}
