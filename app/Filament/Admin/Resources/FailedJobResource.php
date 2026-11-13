<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\FailedJobResource\Pages;
use App\Models\FailedJob;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

/**
 * Failed queue jobs (spec §0.4 F-0.4-003 / §3.27 F-3.27-012, WP-17): inspect
 * payload + exception, retry via queue:retry (pushes the job back and
 * removes the failed row), delete. viewing needs `logs.view`, mutations
 * `logs.manage` (FailedJobPolicy); bulk actions filter per D-28.
 */
class FailedJobResource extends Resource
{
    protected static ?string $model = FailedJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'System Health';

    protected static ?string $slug = 'logs/failed-jobs';

    protected static ?int $navigationSort = 115;

    public static function getModelLabel(): string
    {
        return __('reports.admin.failed_jobs.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('reports.admin.failed_jobs.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.admin.failed_jobs.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('reports.admin.nav_group');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('failed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('job_name')
                    ->label(__('reports.admin.failed_jobs.job'))
                    ->state(fn (FailedJob $record): string => $record->jobName())
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('queue')
                    ->label(__('reports.admin.failed_jobs.queue'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('connection')
                    ->label(__('reports.admin.failed_jobs.connection'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('exception')
                    ->label(__('reports.admin.failed_jobs.exception'))
                    ->state(fn (FailedJob $record): string => $record->exceptionSummary())
                    ->limit(60)
                    ->tooltip(fn (FailedJob $record): string => $record->exceptionSummary()),
                Tables\Columns\TextColumn::make('failed_at')
                    ->label(__('reports.admin.failed_jobs.failed_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('queue')
                    ->label(__('reports.admin.failed_jobs.queue'))
                    ->options(fn (): array => FailedJob::query()
                        ->distinct()
                        ->orderBy('queue')
                        ->pluck('queue', 'queue')
                        ->all()),
            ])
            ->actions([
                // Inspect payload + full exception (F-3.27-012).
                Tables\Actions\Action::make('inspect')
                    ->label(__('reports.admin.failed_jobs.inspect'))
                    ->icon('heroicon-m-magnifying-glass')
                    ->modalHeading(fn (FailedJob $record): string => $record->jobName())
                    ->modalContent(fn (FailedJob $record) => view('filament.admin.failed-jobs.inspect', [
                        'job' => $record,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('reports.actions.close')),

                Tables\Actions\Action::make('retry')
                    ->label(__('reports.admin.failed_jobs.retry'))
                    ->icon('heroicon-m-arrow-path')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FailedJob $record): bool => auth('admin')->user()?->can('update', $record) ?? false)
                    ->action(function (FailedJob $record): void {
                        Artisan::call('queue:retry', ['id' => [(string) $record->uuid]]);

                        Notification::make()->success()
                            ->title(__('reports.notifications.job_retried'))
                            ->send();
                    }),

                Tables\Actions\DeleteAction::make()
                    ->label(__('reports.admin.failed_jobs.delete')),
            ])
            ->bulkActions([
                // D-28: bulk actions run no per-record policies — filter first.
                Tables\Actions\BulkAction::make('retry')
                    ->label(__('reports.admin.failed_jobs.bulk_retry'))
                    ->icon('heroicon-m-arrow-path')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $uuids = $records
                            ->filter(fn (FailedJob $record): bool => auth('admin')->user()?->can('update', $record) ?? false)
                            ->map(fn (FailedJob $record): string => (string) $record->uuid)
                            ->values();

                        if ($uuids->isNotEmpty()) {
                            Artisan::call('queue:retry', ['id' => $uuids->all()]);
                        }

                        Notification::make()->success()
                            ->title(__('reports.notifications.jobs_retried', ['count' => $uuids->count()]))
                            ->send();
                    }),

                Tables\Actions\DeleteBulkAction::make()
                    ->using(fn (Collection $records) => $records
                        ->filter(fn (FailedJob $record): bool => auth('admin')->user()?->can('delete', $record) ?? false)
                        ->each(fn (FailedJob $record) => $record->delete())),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFailedJobs::route('/'),
        ];
    }
}
