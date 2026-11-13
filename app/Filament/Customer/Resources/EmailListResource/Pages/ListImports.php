<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Enums\ImportSource;
use App\Filament\Customer\Resources\EmailListResource;
use App\Models\EmailList;
use App\Models\ImportJob;
use App\Services\Imports\ImportFailedException;
use App\Services\Imports\ImportHistoryService;
use Filament\Actions\Action as HeaderAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Import history + live progress for a list (spec F-2.2.7-011/012 + the
 * history log with re-run). The table polls while runs are active so the
 * progress bar + ETA advance live; finished rows expose the summary
 * counters and the downloadable error CSV.
 */
class ListImports extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = EmailListResource::class;

    protected static string $view = 'filament.customer.resources.email-list-resource.pages.list-imports';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecordModel()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return __('imports.history.title', ['name' => $this->getRecordModel()->name]);
    }

    public function getRecordModel(): EmailList
    {
        /** @var EmailList $record */
        $record = $this->record;

        return $record;
    }

    /** @return array<int, HeaderAction> */
    protected function getHeaderActions(): array
    {
        return [
            HeaderAction::make('new_import')
                ->label(__('imports.history.new_import'))
                ->icon('heroicon-m-arrow-up-tray')
                ->url(fn (): string => EmailListResource::getUrl('import', ['record' => $this->getRecordModel()])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ImportJob::query()->where('list_id', $this->getRecordModel()->id))
            ->poll('5s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('imports.history.started'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('imports.history.source'))
                    ->badge()
                    ->formatStateUsing(fn (ImportSource $state): string => $state->label()),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('imports.history.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ImportJob::STATUS_COMPLETED => 'success',
                        ImportJob::STATUS_COMPLETED_WITH_ERRORS => 'warning',
                        ImportJob::STATUS_FAILED => 'danger',
                        ImportJob::STATUS_PROCESSING => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => __('imports.status.'.$state)),
                Tables\Columns\ViewColumn::make('progress')
                    ->label(__('imports.history.progress'))
                    ->view('filament.customer.tables.columns.import-progress'),
                Tables\Columns\TextColumn::make('imported_count')
                    ->label(__('imports.history.imported'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_count')
                    ->label(__('imports.history.updated'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('skipped_count')
                    ->label(__('imports.history.skipped'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('failed_count')
                    ->label(__('imports.history.failed'))
                    ->toggleable(),
                // F-2.2.7-013: a rolled-back run stays in the history, badged,
                // with how many subscribers the undo touched.
                Tables\Columns\TextColumn::make('rolled_back_at')
                    ->label(__('imports.history.rolled_back'))
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->formatStateUsing(fn (ImportJob $record): string => __('imports.history.rolled_back_count', [
                        'count' => (int) $record->rolled_back_count,
                    ])),
                Tables\Columns\TextColumn::make('message')
                    ->label(__('imports.history.message'))
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\Action::make('errors')
                    ->label(__('imports.history.download_errors'))
                    ->icon('heroicon-m-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (ImportJob $record): bool => $record->error_file_path !== null)
                    ->url(fn (ImportJob $record): string => route('lists.imports.errors', ['uid' => $record->uid])),

                // Re-run (F-2.2.7-013): clone the run configuration into a
                // fresh queued job. ImportHistoryService owns the tenant
                // check and the "the source file is gone" case — a re-run
                // whose upload was pruned is refused with a readable reason
                // instead of queueing a run that will only fail later.
                Tables\Actions\Action::make('rerun')
                    ->label(__('imports.history.rerun'))
                    ->icon('heroicon-m-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading(__('imports.history.rerun_confirm'))
                    ->modalDescription(__('imports.history.rerun_confirm_body'))
                    ->visible(fn (ImportJob $record): bool => $record->isFinished())
                    ->action(function (ImportJob $record): void {
                        try {
                            app(ImportHistoryService::class)->rerun($record);
                        } catch (ImportFailedException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('imports.page.queued'))->success()->send();
                    }),

                // Rollback (F-2.2.7-013): heavy → queued. The modal states
                // the exact scope that ships (created rows deleted, updated
                // rows' overwritten field values restored).
                Tables\Actions\Action::make('rollback')
                    ->label(__('imports.history.rollback'))
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('imports.history.rollback_confirm'))
                    ->modalDescription(__('imports.history.rollback_confirm_body'))
                    ->visible(fn (ImportJob $record): bool => app(ImportHistoryService::class)->canRollback($record))
                    ->action(function (ImportJob $record): void {
                        app(ImportHistoryService::class)->rollback($record);

                        Notification::make()->title(__('imports.history.rollback_queued'))->success()->send();
                    }),
            ]);
    }
}
