<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\CampaignResource\Pages;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\ExportFormat;
use App\Filament\Customer\Resources\CampaignResource;
use App\Jobs\Campaigns\BulkCampaignActionJob;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\CampaignTag;
use App\Models\EmailList;
use App\Services\Campaigns\CampaignCopier;
use App\Services\Campaigns\CampaignExporter;
use App\Services\Campaigns\CampaignLifecycle;
use App\Services\Campaigns\CampaignRecipients;
use App\Services\Campaigns\CampaignStatusMachine;
use App\Services\Reports\CampaignReportExporter;
use App\Services\Content\CampaignPreviewRenderer;
use Filament\Actions as PageActions;
use Filament\Forms;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Campaigns index (spec §2.3.1): status/type tabs, search + filters,
 * live progress polling for sending campaigns, per-status row actions and
 * D-28-filtered bulk actions. Delivery stats columns render "—" until the
 * WP-13 sending engine writes real data.
 */
class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PageActions\CreateAction::make()->label(__('campaigns.actions.create')),
        ];
    }

    /**
     * WP-35 F-2.3.9-006: archived campaigns are hidden everywhere except the
     * "Archived" tab, which is the only place that opts back in.
     */
    protected function getTableQuery(): ?Builder
    {
        $query = parent::getTableQuery();

        if ($query !== null && $this->activeTab !== 'archived') {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('campaigns.tabs.all'))];

        foreach (CampaignType::cases() as $type) {
            $tabs[$type->value] = Tab::make(__('campaigns.tabs.'.$type->value))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type->value));
        }

        foreach ([
            'drafts' => CampaignStatus::Draft,
            'scheduled' => CampaignStatus::Scheduled,
            'sending' => CampaignStatus::Sending,
            'sent' => CampaignStatus::Sent,
            'paused' => CampaignStatus::Paused,
            'blocked' => CampaignStatus::Blocked,
        ] as $key => $status) {
            $tabs[$key] = Tab::make(__('campaigns.tabs.'.$key))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status->value));
        }

        // WP-35 — F-2.3.1-008 starred, F-2.3.9-006 archived.
        $tabs['starred'] = Tab::make(__('campaigns.star.tab'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_starred', true));

        $tabs['archived'] = Tab::make(__('campaigns.archive.tab'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('archived_at'));

        return $tabs;
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll(config('emp-campaigns.index_poll_seconds', 15).'s')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('campaigns.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('campaigns.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (CampaignType $state): string => $state->label())
                    ->color('gray'),

                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('campaigns.fields.list'))
                    ->description(fn (Campaign $record): ?string => $record->segment?->name)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('subject')
                    ->label(__('campaigns.fields.subject'))
                    ->searchable()
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('campaigns.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (CampaignStatus $state): string => $state->label())
                    ->color(fn (CampaignStatus $state): string => $state->color()),

                Tables\Columns\ViewColumn::make('progress')
                    ->label(__('campaigns.fields.progress'))
                    ->view('filament.customer.campaigns.progress'),

                Tables\Columns\TextColumn::make('send_at')
                    ->label(__('campaigns.fields.send_date'))
                    ->state(fn (Campaign $record) => $record->send_at ?? $record->next_run_at)
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('recipients')
                    ->label(__('campaigns.fields.recipients'))
                    ->state(fn (Campaign $record): string => $record->email_list_id !== null
                        ? number_format(app(CampaignRecipients::class)->count($record))
                        : __('campaigns.stats_pending')),

                // WP-13 seam: real delivery/engagement numbers arrive with the
                // sending engine — until then these render "—" (spec §2.3.1).
                Tables\Columns\TextColumn::make('delivered')
                    ->label(__('campaigns.fields.delivered'))
                    ->state(fn (Campaign $record): string => (int) $record->delivery_total > 0
                        ? number_format((int) $record->delivery_sent)
                        : __('campaigns.stats_pending'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('opens')
                    ->label(__('campaigns.fields.opens'))
                    ->state(fn (): string => __('campaigns.stats_pending'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('clicks')
                    ->label(__('campaigns.fields.clicks'))
                    ->state(fn (): string => __('campaigns.stats_pending'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('bounces')
                    ->label(__('campaigns.fields.bounces'))
                    ->state(fn (): string => __('campaigns.stats_pending'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unsubscribes')
                    ->label(__('campaigns.fields.unsubscribes'))
                    ->state(fn (): string => __('campaigns.stats_pending'))
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('email_list_id')
                    ->label(__('campaigns.filters.list'))
                    ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all()),

                // Campaign groups filter (F-2.3.1-007 / F-2.3.10-001).
                Tables\Filters\SelectFilter::make('campaign_group_id')
                    ->label(__('campaigns.filters.group'))
                    ->options(fn (): array => CampaignGroup::query()->orderBy('name')->pluck('name', 'id')->all()),

                Tables\Filters\SelectFilter::make('status')
                    ->label(__('campaigns.filters.status'))
                    ->multiple()
                    ->options(CampaignStatus::options()),

                Tables\Filters\SelectFilter::make('type')
                    ->label(__('campaigns.filters.type'))
                    ->options(CampaignType::options()),

                Tables\Filters\Filter::make('send_between')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('campaigns.fields.created_from')),
                        Forms\Components\DatePicker::make('until')->label(__('campaigns.fields.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('send_at', '>=', $from))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until) => $q->whereDate('send_at', '<=', $until))),

                // WP-35 F-2.3.1-004 / F-2.3.10-002 — tag filter.
                Tables\Filters\SelectFilter::make('campaign_tag')
                    ->label(__('campaigns.tags.filter'))
                    ->options(fn (): array => CampaignTag::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['value'] ?? null,
                            fn (Builder $q, $tagId) => $q->whereHas(
                                'tags',
                                fn (Builder $tag) => $tag->whereKey((int) $tagId),
                            ),
                        )),
            ])
            ->actions($this->rowActions())
            ->bulkActions($this->bulkActions());
    }

    /** @return array<int, Tables\Actions\Action|Tables\Actions\ActionGroup> */
    private function rowActions(): array
    {
        $machine = app(CampaignStatusMachine::class);

        return [
            // Overview / Report (F-2.3.1-005, WP-17): the §2.3.8 report page.
            Tables\Actions\Action::make('report')
                ->label(__('reports.actions.overview'))
                ->icon('heroicon-m-chart-bar')
                ->visible(fn (Campaign $record): bool => auth('customer')->user()?->can('view', $record) ?? false)
                ->url(fn (Campaign $record): string => CampaignResource::getUrl('report', ['record' => $record])),

            // Edit until sending (§2.3.9).
            Tables\Actions\EditAction::make()
                ->visible(fn (Campaign $record): bool => $record->isEditable()
                    && (auth('customer')->user()?->can('update', $record) ?? false)),

            Tables\Actions\Action::make('preview')
                ->label(__('campaigns.actions.preview'))
                ->icon('heroicon-m-eye')
                ->modalHeading(fn (Campaign $record): string => (string) $record->name)
                // WP-46 (§2.3.3 F-2.3.3-009): the CANONICAL engine, not the
                // sampler — a preview must resolve [IF …] blocks (including
                // smart content by segment) or the customer sees raw syntax.
                ->modalContent(fn (Campaign $record) => view('filament.customer.campaigns.preview', [
                    'html' => app(CampaignPreviewRenderer::class)->render($record),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('campaigns.actions.close')),

            Tables\Actions\Action::make('pause')
                ->label(__('campaigns.actions.pause'))
                ->icon('heroicon-m-pause')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Campaign $record): bool => $machine->canPause($record)
                    && (auth('customer')->user()?->can('update', $record) ?? false))
                ->action(function (Campaign $record) use ($machine): void {
                    $machine->transition($record, CampaignStatus::Paused);
                    Notification::make()->success()->title(__('campaigns.notifications.paused'))->send();
                }),

            Tables\Actions\Action::make('resume')
                ->label(__('campaigns.actions.resume'))
                ->icon('heroicon-m-play')
                ->color('success')
                ->visible(fn (Campaign $record): bool => $machine->canResume($record)
                    && (auth('customer')->user()?->can('update', $record) ?? false))
                ->action(function (Campaign $record) use ($machine): void {
                    $machine->resume($record);
                    Notification::make()->success()->title(__('campaigns.notifications.resumed'))->send();
                }),

            // WP-35 F-2.3.1-008 — star / unstar.
            Tables\Actions\Action::make('star')
                ->label(fn (Campaign $record): string => $record->is_starred
                    ? __('campaigns.star.unstar')
                    : __('campaigns.star.action'))
                ->icon('heroicon-m-star')
                ->color(fn (Campaign $record): string => $record->is_starred ? 'warning' : 'gray')
                ->visible(fn (Campaign $record): bool => auth('customer')->user()?->can('update', $record) ?? false)
                ->action(fn (Campaign $record) => app(CampaignLifecycle::class)->toggleStar($record)),

            Tables\Actions\ActionGroup::make([
                // WP-35 F-2.3.9-004 — resend to non-openers.
                Tables\Actions\Action::make('resend_non_openers')
                    ->label(__('campaigns.resend.action'))
                    ->icon('heroicon-m-arrow-path-rounded-square')
                    ->visible(fn (Campaign $record): bool => $record->status === CampaignStatus::Sent
                        && (auth('customer')->user()?->can('update', $record) ?? false))
                    ->form(fn (Campaign $record): array => [
                        Forms\Components\Placeholder::make('non_openers')
                            ->label(__('campaigns.filters.preview', ['count' => '']))
                            ->content(fn (): string => __('campaigns.filters.preview', [
                                'count' => number_format(app(CampaignLifecycle::class)->nonOpenerCount($record)),
                            ])),
                        Forms\Components\TextInput::make('subject')
                            ->label(__('campaigns.resend.subject'))
                            ->default((string) $record->subject)
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (Campaign $record, array $data): void {
                        try {
                            $lifecycle = app(CampaignLifecycle::class);
                            $count = $lifecycle->nonOpenerCount($record);

                            if ($count < 1) {
                                Notification::make()->warning()
                                    ->title(__('campaigns.resend.none_left'))->send();

                                return;
                            }

                            $followUp = $lifecycle->resendToNonOpeners($record, (string) $data['subject']);

                            Notification::make()->success()
                                ->title(__('campaigns.resend.created', [
                                    'name' => $followUp->name,
                                    'count' => number_format($count),
                                ]))
                                ->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()
                                ->title(collect($e->errors())->flatten()->first())
                                ->send();
                        }
                    }),

                // WP-35 F-2.3.9-006 — archive / unarchive.
                Tables\Actions\Action::make('archive')
                    ->label(fn (Campaign $record): string => $record->isArchived()
                        ? __('campaigns.archive.unarchive')
                        : __('campaigns.archive.action'))
                    ->icon('heroicon-m-archive-box')
                    ->visible(fn (Campaign $record): bool => auth('customer')->user()?->can('update', $record) ?? false)
                    ->action(function (Campaign $record): void {
                        $lifecycle = app(CampaignLifecycle::class);

                        try {
                            if ($record->isArchived()) {
                                $lifecycle->unarchive($record);
                                Notification::make()->success()->title(__('campaigns.archive.unarchived'))->send();
                            } else {
                                $lifecycle->archive($record);
                                Notification::make()->success()->title(__('campaigns.archive.archived'))->send();
                            }
                        } catch (ValidationException $e) {
                            Notification::make()->danger()
                                ->title(collect($e->errors())->flatten()->first())
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('copy')
                    ->label(__('campaigns.actions.copy'))
                    ->icon('heroicon-m-document-duplicate')
                    ->form([
                        Forms\Components\Toggle::make('with_content')
                            ->label(__('campaigns.actions.copy_with_content'))
                            ->default(true),
                        Forms\Components\Toggle::make('with_options')
                            ->label(__('campaigns.actions.copy_with_options'))
                            ->default(true),
                    ])
                    ->action(function (Campaign $record, array $data): void {
                        try {
                            app(CampaignCopier::class)->copy(
                                $record,
                                withContent: (bool) $data['with_content'],
                                withOptions: (bool) $data['with_options'],
                            );

                            Notification::make()->success()->title(__('campaigns.notifications.copied'))->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()
                                ->title(collect($e->errors())->flatten()->first())
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('block')
                    ->label(__('campaigns.actions.block'))
                    ->icon('heroicon-m-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Campaign $record): bool => $machine->canTransition($record->status, CampaignStatus::Blocked)
                        && (auth('customer')->user()?->can('update', $record) ?? false))
                    ->action(function (Campaign $record) use ($machine): void {
                        $machine->transition($record, CampaignStatus::Blocked);
                        Notification::make()->success()->title(__('campaigns.notifications.blocked'))->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label(__('campaigns.actions.cancel'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Campaign $record): bool => $machine->canTransition($record->status, CampaignStatus::Cancelled)
                        && (auth('customer')->user()?->can('update', $record) ?? false))
                    ->action(function (Campaign $record) use ($machine): void {
                        $machine->transition($record, CampaignStatus::Cancelled);
                        Notification::make()->success()->title(__('campaigns.notifications.cancelled'))->send();
                    }),

                // Public share-report URL (F-2.3.4-020 / F-2.3.1-005).
                Tables\Actions\Action::make('share_report')
                    ->label(__('campaigns.actions.share_report'))
                    ->icon('heroicon-m-link')
                    ->visible(fn (Campaign $record): bool => $record->shareReportUrl() !== null)
                    ->modalHeading(__('campaigns.options.share_report_url'))
                    ->modalContent(fn (Campaign $record) => view('filament.customer.campaigns.share-url', [
                        'url' => $record->shareReportUrl(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('campaigns.actions.close')),

                // Queued full-report export: ZIP of every §2.3.8 tab CSV
                // (F-2.3.1-005 "Export report" / F-2.3.8-018, WP-17).
                Tables\Actions\Action::make('export_report')
                    ->label(__('reports.actions.export_report'))
                    ->icon('heroicon-m-document-chart-bar')
                    ->visible(fn (Campaign $record): bool => auth('customer')->user()?->can('view', $record) ?? false)
                    // F-2.3.8-018 (WP-26) format chooser, CSV preselected.
                    ->form([
                        Radio::make('format')
                            ->label(__('reports.export.format'))
                            ->options(ExportFormat::options())
                            ->default(ExportFormat::Csv->value)
                            ->inline()
                            ->required(),
                    ])
                    ->action(function (Campaign $record, array $data): void {
                        app(CampaignReportExporter::class)->queue(
                            $record,
                            CampaignReportExporter::FULL,
                            $data['format'] ?? null,
                        );

                        Notification::make()->success()
                            ->title(__('reports.notifications.export_queued'))
                            ->send();
                    }),

                Tables\Actions\Action::make('export_html')
                    ->label(__('campaigns.actions.export_html'))
                    ->icon('heroicon-m-code-bracket')
                    ->action(function (Campaign $record) {
                        $exporter = app(CampaignExporter::class);

                        return response()->streamDownload(
                            function () use ($exporter, $record): void {
                                echo $exporter->html($record);
                            },
                            $exporter->fileName($record, 'html'),
                            ['Content-Type' => 'text/html; charset=UTF-8'],
                        );
                    }),

                Tables\Actions\Action::make('export_zip')
                    ->label(__('campaigns.actions.export_zip'))
                    ->icon('heroicon-m-archive-box-arrow-down')
                    ->action(function (Campaign $record) {
                        $exporter = app(CampaignExporter::class);

                        return response()
                            ->download($exporter->zip($record), $exporter->fileName($record, 'zip'))
                            ->deleteFileAfterSend();
                    }),

                Tables\Actions\DeleteAction::make(),
            ])->label(__('campaigns.actions.more'))->icon('heroicon-m-ellipsis-vertical'),
        ];
    }

    /** @return array<int, Tables\Actions\BulkAction> */
    private function bulkActions(): array
    {
        $machine = app(CampaignStatusMachine::class);

        return [
            // D-28: bulk actions run no per-record policies — rows the actor
            // cannot mutate (or whose status forbids the move) are filtered
            // out explicitly, then the work is QUEUED (Part 8
            // F-Phase-3-Differentiat-006 / docs/ARCHITECTURE.md rule 5).
            Tables\Actions\DeleteBulkAction::make()
                ->label(__('campaigns.actions.bulk_delete'))
                ->using(fn (Collection $records) => $this->queueBulk(
                    BulkCampaignActionJob::ACTION_DELETE,
                    $records->filter(fn (Campaign $record): bool => auth('customer')->user()?->can('delete', $record) ?? false),
                )),

            Tables\Actions\BulkAction::make('pause')
                ->label(__('campaigns.actions.bulk_pause'))
                ->icon('heroicon-m-pause')
                ->requiresConfirmation()
                ->deselectRecordsAfterCompletion()
                ->action(function (Collection $records) use ($machine): void {
                    $this->queueBulk(
                        BulkCampaignActionJob::ACTION_PAUSE,
                        $records->filter(fn (Campaign $record): bool => $machine->canPause($record)
                            && (auth('customer')->user()?->can('update', $record) ?? false)),
                    );
                }),

            // Bulk report export (F-2.3.1-006, WP-17). D-28: rows the actor
            // cannot view are filtered out before any job is queued.
            Tables\Actions\BulkAction::make('export_report')
                ->label(__('reports.actions.bulk_export'))
                ->icon('heroicon-m-document-chart-bar')
                ->requiresConfirmation()
                ->deselectRecordsAfterCompletion()
                ->form([
                    Radio::make('format')
                        ->label(__('reports.export.format'))
                        ->options(ExportFormat::options())
                        ->default(ExportFormat::Csv->value)
                        ->inline()
                        ->required(),
                ])
                ->action(function (Collection $records, array $data): void {
                    $format = $data['format'] ?? null;

                    $count = $records
                        ->filter(fn (Campaign $record): bool => auth('customer')->user()?->can('view', $record) ?? false)
                        ->each(fn (Campaign $record) => app(CampaignReportExporter::class)->queue(
                            $record,
                            CampaignReportExporter::FULL,
                            $format,
                        ))
                        ->count();

                    Notification::make()->success()
                        ->title(__('reports.notifications.bulk_export_queued', ['count' => $count]))
                        ->send();
                }),

            Tables\Actions\BulkAction::make('resume')
                ->label(__('campaigns.actions.bulk_resume'))
                ->icon('heroicon-m-play')
                ->requiresConfirmation()
                ->deselectRecordsAfterCompletion()
                ->action(function (Collection $records) use ($machine): void {
                    $this->queueBulk(
                        BulkCampaignActionJob::ACTION_RESUME,
                        $records->filter(fn (Campaign $record): bool => $machine->canResume($record)
                            && (auth('customer')->user()?->can('update', $record) ?? false)),
                    );
                }),
        ];
    }

    /**
     * Dispatch one queued job for an already-authorized selection and report
     * how many rows were handed over (F-Phase-3-Differentiat-006). The job
     * re-checks the status machine at execution time.
     *
     * @param  Collection<int, Campaign>  $records
     */
    private function queueBulk(string $action, Collection $records): void
    {
        $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        if ($ids !== []) {
            BulkCampaignActionJob::dispatch(
                $action,
                $ids,
                (int) auth('customer')->id(),
                (string) Str::uuid(),
            );
        }

        Notification::make()->success()
            ->title(__('campaigns.notifications.bulk_queued', ['count' => count($ids)]))
            ->send();
    }
}
