<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Enums\ExportFormat;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentMatchType;
use App\Enums\SegmentOperator;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource;
use App\Jobs\Exports\RunExportJob;
use App\Jobs\Segments\RefreshSegmentSnapshotJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\ListSegment;
use App\Models\ListSegmentCondition;
use App\Services\Exports\ExportColumns;
use App\Services\Segments\SegmentQueryBuilder;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Segments manager for a list (spec §2.2.10): ALL/ANY matching, field / date
 * / status / campaign conditions, live subscriber-count preview before
 * saving (F-2.2.10-010) and duplicate + export actions (F-2.2.10-014).
 * Membership is dynamic by default — the count column re-evaluates on every
 * render (F-2.2.10-012). Campaign conditions (F-2.2.10-003) pick a campaign —
 * or ANY campaign in the last N days — and, for "clicked specific link", one of
 * that campaign's tracked URLs.
 *
 * WP-42 additions: engagement (F-2.2.10-006), location (F-2.2.10-007) and
 * automation (F-2.2.10-009) conditions, per-condition groups for AND-of-ORs
 * (F-2.2.10-011) and the static/frozen snapshot toggle plus its queued
 * "Refresh snapshot" action (F-2.2.10-013).
 */
class ManageListSegments extends ManageRelatedRecords
{
    protected static string $resource = EmailListResource::class;

    protected static string $relationship = 'segments';

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    public static function getNavigationLabel(): string
    {
        return __('segments.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('segments.page_title', ['name' => $this->getOwnerRecord()->name]);
    }

    public function getOwnerRecord(): EmailList
    {
        /** @var EmailList $record */
        $record = $this->getRecord();

        return $record;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('segments.form.name'))
                ->required()
                ->maxLength(191),
            Forms\Components\Radio::make('match_type')
                ->label(__('segments.form.match_type'))
                ->options([
                    'all' => __('segments.match.all'),
                    'any' => __('segments.match.any'),
                ])
                ->default('all')
                ->required()
                ->inline()
                ->live(),
            // Static / frozen snapshot (F-2.2.10-013). Freezing itself is a
            // queued job — see queueSnapshot().
            Forms\Components\Toggle::make('is_static')
                ->label(__('segments.form.is_static'))
                ->helperText(__('segments.form.is_static_help'))
                ->default(false)
                ->live(),
            Forms\Components\Repeater::make('conditions')
                ->label(__('segments.form.conditions'))
                ->relationship('conditions')
                ->orderColumn('sort_order')
                ->reorderable()
                ->defaultItems(1)
                ->schema($this->conditionSchema())
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + $this->tenantData())
                ->live(),
            // Live subscriber-count preview before saving (F-2.2.10-010).
            Forms\Components\Placeholder::make('preview')
                ->label(__('segments.form.preview'))
                ->content(function (Forms\Get $get): string {
                    return $this->previewCount(
                        (string) ($get('match_type') ?? 'all'),
                        array_values((array) $get('conditions')),
                    );
                }),
        ])->columns(1);
    }

    /** @return array<int, mixed> */
    private function conditionSchema(): array
    {
        return [
            Forms\Components\Select::make('type')
                ->label(__('segments.form.condition_type'))
                ->options(SegmentConditionType::options())
                ->default(SegmentConditionType::Field->value)
                ->required()
                ->live(),
            Forms\Components\Grid::make(3)->schema([
                // Field conditions target a custom field or (empty) the email column.
                Forms\Components\Select::make('list_field_id')
                    ->label(__('segments.form.field'))
                    ->options(fn (): array => $this->getOwnerRecord()->fields()->pluck('label', 'id')->all())
                    ->placeholder(__('segments.fields.email'))
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Field->value)
                    ->live(),
                Forms\Components\Select::make('field_key')
                    ->label(__('segments.form.date_key'))
                    ->options([
                        ListSegmentCondition::KEY_SUBSCRIBED => __('segments.fields.subscribed'),
                        ListSegmentCondition::KEY_LAST_ACTIVITY => __('segments.fields.last_activity'),
                    ])
                    ->default(ListSegmentCondition::KEY_SUBSCRIBED)
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Date->value)
                    ->live(),
                // Geo conditions (F-2.2.10-007) pick which location field to test.
                Forms\Components\Select::make('field_key')
                    ->label(__('segments.form.geo_key'))
                    ->options([
                        ListSegmentCondition::KEY_COUNTRY => __('segments.fields.country'),
                        ListSegmentCondition::KEY_REGION => __('segments.fields.region'),
                        ListSegmentCondition::KEY_CITY => __('segments.fields.city'),
                        ListSegmentCondition::KEY_TIMEZONE => __('segments.fields.timezone'),
                    ])
                    ->default(ListSegmentCondition::KEY_COUNTRY)
                    ->helperText(__('segments.form.geo_help'))
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Geo->value)
                    ->live(),
                Forms\Components\Select::make('operator')
                    ->label(__('segments.form.operator'))
                    ->options(fn (Forms\Get $get): array => SegmentOperator::optionsFor(
                        SegmentConditionType::tryFrom((string) $get('type'))?->operators()
                            ?? SegmentOperator::forField(),
                    ))
                    ->required()
                    ->live(),
                // Value inputs, typed per condition family.
                Forms\Components\Select::make('value')
                    ->label(__('segments.form.value'))
                    ->options(SubscriberStatus::options())
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Status->value)
                    ->live(),
                Forms\Components\DatePicker::make('value')
                    ->label(__('segments.form.value'))
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Date->value
                        && (SegmentOperator::tryFrom((string) $get('operator'))?->needsValue() ?? false))
                    ->live(),
                Forms\Components\DatePicker::make('value2')
                    ->label(__('segments.form.value2'))
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Date->value
                        && (SegmentOperator::tryFrom((string) $get('operator'))?->needsSecondValue() ?? false))
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label(__('segments.form.value'))
                    ->visible(fn (Forms\Get $get): bool => in_array($get('type'), [
                        SegmentConditionType::Field->value,
                        SegmentConditionType::Campaign->value,
                        SegmentConditionType::Geo->value,
                    ], true)
                        && (SegmentOperator::tryFrom((string) $get('operator'))?->needsValue() ?? false))
                    ->live(onBlur: true),
                // Engagement conditions (F-2.2.10-006): the N of "opened at
                // least N times" / "no opens in the last N days".
                Forms\Components\TextInput::make('value')
                    ->label(__('segments.form.engagement_value'))
                    ->helperText(__('segments.form.engagement_help'))
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Engagement->value)
                    ->live(onBlur: true),
                Forms\Components\TextInput::make('value2')
                    ->label(__('segments.form.value2'))
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Field->value
                        && (SegmentOperator::tryFrom((string) $get('operator'))?->needsSecondValue() ?? false))
                    ->live(onBlur: true),
                // Campaign parameters (F-2.2.10-003). Empty campaign = ANY
                // campaign of this tenant; within_days bounds the window.
                Forms\Components\Select::make('settings.campaign_id')
                    ->label(__('segments.form.campaign'))
                    ->options(fn (): array => app(SegmentQueryBuilder::class)->campaignOptions($this->getOwnerRecord()))
                    ->placeholder(__('segments.form.campaign_any'))
                    ->searchable()
                    ->visible(fn (Forms\Get $get): bool => in_array($get('type'), [
                        SegmentConditionType::Campaign->value,
                        SegmentConditionType::Engagement->value,
                    ], true))
                    ->live(),
                // Automation conditions (F-2.2.10-009) — tenant-scoped picker.
                Forms\Components\Select::make('settings.automation_id')
                    ->label(__('segments.form.automation'))
                    ->options(fn (): array => app(SegmentQueryBuilder::class)->automationOptions($this->getOwnerRecord()))
                    ->searchable()
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Automation->value)
                    ->live(),
                Forms\Components\Select::make('settings.link_id')
                    ->label(__('segments.form.link'))
                    ->options(fn (Forms\Get $get): array => app(SegmentQueryBuilder::class)
                        ->linkOptions($this->getOwnerRecord(), $get('settings.campaign_id')))
                    ->helperText(__('segments.form.link_help'))
                    ->searchable()
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Campaign->value
                        && $get('operator') === SegmentOperator::ClickedLink->value)
                    ->live(),
                Forms\Components\TextInput::make('settings.within_days')
                    ->label(__('segments.form.within_days'))
                    ->helperText(__('segments.form.within_days_help'))
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (Forms\Get $get): bool => in_array($get('type'), [
                        SegmentConditionType::Campaign->value,
                        SegmentConditionType::Engagement->value,
                        // WP-45 (§2.12 F-2.12-006): the same window bounds a
                        // purchase condition — "spent 100 in the last 90 days".
                        SegmentConditionType::Purchase->value,
                    ], true)),
                // ---- WP-45 §2.12 F-2.12-006: purchase conditions ----
                // Money in MINOR UNITS, orders/days as plain counts. The
                // helper text says which, per operator, so nobody has to guess
                // whether 100 means one euro or one hundred.
                Forms\Components\TextInput::make('value')
                    ->label(fn (Forms\Get $get): string => __(
                        'segments.form.purchase_value_'.(
                            in_array($get('operator'), [
                                SegmentOperator::SpentAtLeast->value,
                                SegmentOperator::SpentAtMost->value,
                            ], true) ? 'money' : (
                                in_array($get('operator'), [
                                    SegmentOperator::LastOrderWithinDays->value,
                                    SegmentOperator::LastOrderBeforeDays->value,
                                ], true) ? 'days' : 'count'
                            )
                        )
                    ))
                    ->helperText(__('segments.form.purchase_value_help'))
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Purchase->value
                        && in_array($get('operator'), [
                            SegmentOperator::SpentAtLeast->value,
                            SegmentOperator::SpentAtMost->value,
                            SegmentOperator::OrderedAtLeast->value,
                            SegmentOperator::OrderedAtMost->value,
                            SegmentOperator::LastOrderWithinDays->value,
                            SegmentOperator::LastOrderBeforeDays->value,
                        ], true))
                    ->live(onBlur: true),
                Forms\Components\Select::make('value')
                    ->label(__('segments.form.purchase_category'))
                    ->options(fn (): array => app(SegmentQueryBuilder::class)
                        ->purchaseCategoryOptions($this->getOwnerRecord()))
                    ->helperText(__('segments.form.purchase_category_help'))
                    ->searchable()
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Purchase->value
                        && in_array($get('operator'), [
                            SegmentOperator::OrderedCategory->value,
                            SegmentOperator::NotOrderedCategory->value,
                        ], true))
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label(__('segments.form.purchase_product'))
                    ->helperText(__('segments.form.purchase_product_help'))
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Purchase->value
                        && $get('operator') === SegmentOperator::OrderedProduct->value)
                    ->live(onBlur: true),
                Forms\Components\Select::make('settings.store_connection_id')
                    ->label(__('segments.form.store'))
                    ->options(fn (): array => app(SegmentQueryBuilder::class)
                        ->storeOptions($this->getOwnerRecord()))
                    ->placeholder(__('segments.form.store_any'))
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Purchase->value)
                    ->live(),
                Forms\Components\TextInput::make('settings.currency')
                    ->label(__('segments.form.purchase_currency'))
                    ->helperText(__('segments.form.purchase_currency_help'))
                    ->maxLength(3)
                    ->visible(fn (Forms\Get $get): bool => $get('type') === SegmentConditionType::Purchase->value
                        && in_array($get('operator'), [
                            SegmentOperator::SpentAtLeast->value,
                            SegmentOperator::SpentAtMost->value,
                        ], true)),
            ]),
            // Nested condition groups (F-2.2.10-011): conditions sharing a
            // group number are combined with that group's operator, and the
            // groups themselves are combined with the segment's match type —
            // "ALL" + per-group "ANY" is the spec's AND of ORs.
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('group_index')
                    ->label(__('segments.form.group_index'))
                    ->helperText(__('segments.form.group_index_help'))
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->live(onBlur: true),
                Forms\Components\Select::make('group_match')
                    ->label(__('segments.form.group_match'))
                    ->options(SegmentMatchType::options())
                    ->placeholder(__('segments.form.group_match_default'))
                    ->live(),
            ]),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('segments.form.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('match_type')
                    ->label(__('segments.form.match_type'))
                    ->badge()
                    ->formatStateUsing(fn (SegmentMatchType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('conditions_count')
                    ->label(__('segments.form.conditions'))
                    ->counts('conditions'),
                // Dynamic vs frozen (F-2.2.10-013).
                Tables\Columns\TextColumn::make('is_static')
                    ->label(__('segments.index.kind'))
                    ->badge()
                    ->state(fn (ListSegment $record): string => $record->isStatic()
                        ? __('segments.index.kind_static')
                        : __('segments.index.kind_dynamic')),
                Tables\Columns\TextColumn::make('snapshot_taken_at')
                    ->label(__('segments.index.snapshot'))
                    ->state(function (ListSegment $record): string {
                        if (! $record->isStatic()) {
                            return '—';
                        }

                        if (! $record->hasSnapshot()) {
                            return __('segments.index.snapshot_pending');
                        }

                        return __('segments.index.snapshot_taken', [
                            'count' => number_format((int) $record->snapshot_count),
                            'date' => $record->snapshot_taken_at?->toDayDateTimeString() ?? '',
                        ]);
                    }),
                // Membership — recalculated at read time for dynamic segments
                // (F-2.2.10-012), read from the snapshot for static ones.
                Tables\Columns\TextColumn::make('subscriber_count')
                    ->label(__('segments.index.subscribers'))
                    ->state(function (ListSegment $record): string {
                        try {
                            return number_format(app(SegmentQueryBuilder::class)->count($record));
                        } catch (Throwable) {
                            return '—';
                        }
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('segments.index.created'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('segments.actions.create'))
                    ->mutateFormDataUsing(fn (array $data): array => $data + $this->tenantData())
                    ->after(fn (ListSegment $record) => $this->queueSnapshotIfNeeded($record)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->after(fn (ListSegment $record) => $this->queueSnapshotIfNeeded($record)),
                // Duplicate segment (F-2.2.10-014).
                Tables\Actions\Action::make('duplicate')
                    ->label(__('segments.actions.duplicate'))
                    ->icon('heroicon-m-document-duplicate')
                    ->action(function (ListSegment $record): void {
                        // conditions_count is a withCount aggregate, not a column.
                        // The snapshot columns are reset by ListSegment::replicating():
                        // the copy has no snapshot rows, so it must not claim one.
                        $copy = $record->replicate(['uid', 'conditions_count']);
                        $copy->name = __('segments.duplicate_name', ['name' => $record->name]);
                        $copy->save();

                        foreach ($record->conditions as $condition) {
                            $clone = $condition->replicate();
                            $clone->list_segment_id = $copy->id;
                            $clone->save();
                        }

                        $this->queueSnapshotIfNeeded($copy);

                        Notification::make()
                            ->title(__('segments.actions.duplicated'))
                            ->success()
                            ->send();
                    }),
                // Re-freeze a static segment (F-2.2.10-013) — queued, never inline.
                Tables\Actions\Action::make('refresh_snapshot')
                    ->label(__('segments.actions.refresh_snapshot'))
                    ->icon('heroicon-m-arrow-path')
                    ->requiresConfirmation()
                    ->modalDescription(__('segments.actions.refresh_snapshot_confirm'))
                    ->visible(fn (ListSegment $record): bool => $record->isStatic())
                    ->action(function (ListSegment $record): void {
                        dispatch(RefreshSegmentSnapshotJob::for($record));

                        Notification::make()
                            ->title(__('segments.actions.refresh_snapshot_queued'))
                            ->success()
                            ->send();
                    }),
                // Export segment (F-2.2.10-014) — reuses the §2.2.8 export
                // engine: queued RunExportJob, chosen columns/format, signed
                // download mailed when it finishes.
                $this->exportAction(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Queued segment export (F-2.2.10-014). The export engine already knows
     * the `segment` scope — it re-evaluates the segment through
     * SegmentQueryBuilder at write time, so the file always holds the segment's
     * live membership (F-2.2.10-012), never a frozen snapshot.
     */
    private function exportAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('export')
            ->label(__('segments.actions.export'))
            ->icon('heroicon-m-arrow-down-tray')
            ->form(fn (): array => [
                Forms\Components\CheckboxList::make('columns')
                    ->label(__('imports.export.columns_label'))
                    ->options(app(ExportColumns::class)->optionsFor($this->getOwnerRecord()))
                    ->default(ExportColumns::STANDARD)
                    ->required()
                    ->columns(3),
                Forms\Components\Radio::make('format')
                    ->label(__('imports.export.format'))
                    ->options(ExportFormat::options())
                    ->default(ExportFormat::Csv->value)
                    ->inline()
                    ->required(),
            ])
            ->action(function (ListSegment $record, array $data): void {
                $list = $this->getOwnerRecord();

                $export = ExportJob::create([
                    'customer_id' => $list->customer_id,
                    'list_id' => $list->id,
                    'scope' => ExportJob::SCOPE_SEGMENT,
                    'format' => ExportFormat::fromValue($data['format'] ?? null),
                    'columns' => array_values((array) $data['columns']),
                    'list_segment_id' => (int) $record->id,
                ]);

                RunExportJob::dispatch((int) $export->id);

                Notification::make()->title(__('imports.export.queued'))->success()->send();
            });
    }

    /**
     * Live count preview from unsaved form state (F-2.2.10-010).
     *
     * @param  list<array<string, mixed>>  $conditions
     */
    public function previewCount(string $match, array $conditions): string
    {
        try {
            $count = app(SegmentQueryBuilder::class)->countFor(
                $this->getOwnerRecord(),
                $match === '' ? 'all' : $match,
                $conditions,
            );

            return trans_choice('segments.form.preview_count', $count, ['count' => number_format($count)]);
        } catch (Throwable) {
            return __('segments.form.preview_incomplete');
        }
    }

    /**
     * Queue the snapshot work after a save (F-2.2.10-013):
     *  - a static segment with no snapshot yet must be frozen (until then it
     *    matches nobody — it never falls back to the live set);
     *  - a segment switched back to dynamic must have its stale snapshot
     *    dropped, so re-freezing later starts from scratch.
     *
     * A static segment that already has a snapshot is deliberately left alone:
     * editing its name must not silently re-freeze it. The explicit "Refresh
     * snapshot" action is how a customer asks for that.
     */
    private function queueSnapshotIfNeeded(ListSegment $record): void
    {
        $needsFreeze = $record->isStatic() && ! $record->hasSnapshot();
        $needsClear = ! $record->isStatic() && $record->hasSnapshot();

        if (! $needsFreeze && ! $needsClear) {
            return;
        }

        dispatch(RefreshSegmentSnapshotJob::for($record));
    }

    /** @return array<string, int> */
    private function tenantData(): array
    {
        $user = Filament::auth()->user();

        return [
            'customer_id' => $user instanceof Customer ? $user->id : (int) $this->getOwnerRecord()->customer_id,
        ];
    }
}
