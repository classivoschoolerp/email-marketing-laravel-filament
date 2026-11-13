<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\ExportFormat;
use App\Enums\OptInType;
use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Filament\Customer\Resources\EmailListResource\Pages;
use App\Jobs\Exports\RunExportJob;
use App\Jobs\Lists\CleanListJob;
use App\Jobs\Lists\CopyListJob;
use App\Jobs\Lists\DeduplicateListsJob;
use App\Jobs\Lists\MergeListsJob;
use App\Jobs\Lists\PurgeListJob;
use App\Jobs\Lists\SplitListJob;
use App\Jobs\Lists\SyncListsJob;
use App\Jobs\Verification\VerifyBatchJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ExportJob;
use App\Models\VerificationJob;
use App\Services\Exports\ExportColumns;
use App\Services\Verification\Credentials;
use App\Services\Verification\ProviderRegistry;
use App\Support\Geo\Countries;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Lists CRUD (spec §2.2.1/§2.2.2/§2.2.14). The model is EmailList because
 * PHP reserves `List`; the table stays `lists`.
 */
class EmailListResource extends Resource
{
    protected static ?string $model = EmailList::class;

    protected static ?string $slug = 'lists';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('lists.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('lists.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('lists.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lists.model_label_plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Defence in depth on top of the CustomerScope global scope: Livewire
        // update requests re-run this query, so pin the tenant from the
        // authenticated customer, never from UI state (docs/ARCHITECTURE.md rule 3).
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('list')
                ->tabs([
                    Tabs\Tab::make(__('lists.tabs.general'))->schema(self::generalSchema()),
                    Tabs\Tab::make(__('lists.tabs.defaults'))->schema(self::defaultsSchema()),
                    Tabs\Tab::make(__('lists.tabs.notifications'))->schema(self::notificationsSchema()),
                    Tabs\Tab::make(__('lists.tabs.company'))->schema(self::companySchema()),
                    Tabs\Tab::make(__('lists.tabs.subscriber_actions'))->schema(self::subscriberActionsSchema()),
                    Tabs\Tab::make(__('lists.tabs.advanced'))->schema(self::advancedSchema()),
                ])
                ->columnSpanFull(),
        ]);
    }

    /** Tab: General (F-2.2.2-001..007). @return array<int, mixed> */
    public static function generalSchema(): array
    {
        return [
            Forms\Components\TextInput::make('name')
                ->label(__('lists.form.name'))
                ->required()
                ->maxLength(191),
            Forms\Components\Textarea::make('description')
                ->label(__('lists.form.description'))
                ->rows(3)
                ->maxLength(65535),
            Forms\Components\Radio::make('opt_in')
                ->label(__('lists.form.opt_in'))
                ->options(OptInType::options())
                ->default(OptInType::Single->value)
                ->required()
                ->inline(),
            Forms\Components\Radio::make('opt_out')
                ->label(__('lists.form.opt_out'))
                ->options(OptInType::options())
                ->default(OptInType::Single->value)
                ->required()
                ->inline(),
            Forms\Components\Toggle::make('require_approval')
                ->label(__('lists.form.require_approval'))
                ->helperText(__('lists.form.require_approval_help')),
            Forms\Components\Radio::make('import_duplicate_handling')
                ->label(__('lists.form.import_duplicate_handling'))
                ->options([
                    'update' => __('lists.form.duplicates_update'),
                    'skip' => __('lists.form.duplicates_skip'),
                ])
                ->default('update')
                ->required()
                ->inline(),
            Forms\Components\Toggle::make('visible')
                ->label(__('lists.form.visible'))
                ->helperText(__('lists.form.visible_help'))
                ->default(true),
            Forms\Components\TagsInput::make('list_tags')
                ->label(__('lists.form.tags'))
                ->placeholder(__('lists.form.tags_placeholder')),
        ];
    }

    /** Tab: Defaults (F-2.2.2-008). @return array<int, mixed> */
    public static function defaultsSchema(): array
    {
        return [
            Forms\Components\TextInput::make('from_name')
                ->label(__('lists.form.from_name'))
                ->maxLength(191),
            Forms\Components\TextInput::make('from_email')
                ->label(__('lists.form.from_email'))
                ->email()
                ->maxLength(191),
            Forms\Components\TextInput::make('reply_to')
                ->label(__('lists.form.reply_to'))
                ->email()
                ->maxLength(191),
            Forms\Components\TextInput::make('default_subject')
                ->label(__('lists.form.default_subject'))
                ->maxLength(191),
        ];
    }

    /** Tab: Notifications (F-2.2.2-009/010). @return array<int, mixed> */
    public static function notificationsSchema(): array
    {
        return [
            Forms\Components\Toggle::make('subscribe_notify')
                ->label(__('lists.form.subscribe_notify'))
                ->live(),
            Forms\Components\TextInput::make('subscribe_notify_emails')
                ->label(__('lists.form.subscribe_notify_emails'))
                ->helperText(__('lists.form.notify_emails_help'))
                ->visible(fn (Forms\Get $get): bool => (bool) $get('subscribe_notify'))
                ->requiredIfAccepted('subscribe_notify')
                ->rule(fn (): Closure => self::commaSeparatedEmailsRule()),
            Forms\Components\Toggle::make('unsubscribe_notify')
                ->label(__('lists.form.unsubscribe_notify'))
                ->live(),
            Forms\Components\TextInput::make('unsubscribe_notify_emails')
                ->label(__('lists.form.unsubscribe_notify_emails'))
                ->helperText(__('lists.form.notify_emails_help'))
                ->visible(fn (Forms\Get $get): bool => (bool) $get('unsubscribe_notify'))
                ->requiredIfAccepted('unsubscribe_notify')
                ->rule(fn (): Closure => self::commaSeparatedEmailsRule()),
        ];
    }

    /** Tab: Company / CAN-SPAM (F-2.2.2-012..014). @return array<int, mixed> */
    public static function companySchema(): array
    {
        return [
            Forms\Components\Actions::make([
                Forms\Components\Actions\Action::make('copy_from_account')
                    ->label(__('lists.form.copy_from_account'))
                    ->icon('heroicon-m-building-office')
                    ->action(function (Forms\Set $set): void {
                        $customer = Filament::auth()->user();

                        if (! $customer instanceof Customer) {
                            return;
                        }

                        // Customer company columns (spec §2.15.1) → list CAN-SPAM fields.
                        $set('company_name', $customer->company);
                        $set('company_address_1', $customer->company_address);
                        $set('company_city', $customer->company_city);
                        $set('company_zone', $customer->company_zone);
                        $set('company_country', $customer->company_country);
                        $set('company_zip', $customer->company_zip);
                        $set('company_phone', $customer->phone);
                        $set('company_website', $customer->company_website);
                    }),
            ]),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('company_name')
                    ->label(__('lists.form.company_name'))
                    ->maxLength(191),
                Forms\Components\TextInput::make('company_address_1')
                    ->label(__('lists.form.company_address_1'))
                    ->maxLength(191),
                Forms\Components\TextInput::make('company_address_2')
                    ->label(__('lists.form.company_address_2'))
                    ->maxLength(191),
                Forms\Components\TextInput::make('company_city')
                    ->label(__('lists.form.company_city'))
                    ->maxLength(100),
                Forms\Components\TextInput::make('company_zone')
                    ->label(__('lists.form.company_zone'))
                    ->maxLength(100),
                Forms\Components\Select::make('company_country')
                    ->label(__('lists.form.company_country'))
                    ->options(Countries::all())
                    ->searchable(),
                Forms\Components\TextInput::make('company_zip')
                    ->label(__('lists.form.company_zip'))
                    ->maxLength(32),
                Forms\Components\TextInput::make('company_phone')
                    ->label(__('lists.form.company_phone'))
                    ->tel()
                    ->maxLength(64),
                Forms\Components\TextInput::make('company_website')
                    ->label(__('lists.form.company_website'))
                    ->url()
                    ->maxLength(191),
            ]),
            Forms\Components\Textarea::make('company_footer')
                ->label(__('lists.form.company_footer'))
                ->helperText(__('lists.form.company_footer_help'))
                ->rows(4),
        ];
    }

    /** Tab: Subscriber actions (F-2.2.2-015..018). @return array<int, mixed> */
    public static function subscriberActionsSchema(): array
    {
        $otherLists = fn (?EmailList $record): array => EmailList::query()
            ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        $select = fn (string $name, string $label) => Forms\Components\Select::make($name)
            ->label($label)
            ->multiple()
            ->options($otherLists)
            ->helperText(__('lists.form.subscriber_actions_help'));

        return [
            Forms\Components\Section::make(__('lists.form.on_subscribe'))->schema([
                $select('on_subscribe_subscribe_ids', __('lists.form.on_subscribe_subscribe')),
                $select('on_subscribe_unsubscribe_ids', __('lists.form.on_subscribe_unsubscribe')),
            ]),
            Forms\Components\Section::make(__('lists.form.on_unsubscribe'))->schema([
                $select('on_unsubscribe_unsubscribe_ids', __('lists.form.on_unsubscribe_unsubscribe')),
                $select('on_unsubscribe_subscribe_ids', __('lists.form.on_unsubscribe_subscribe')),
            ]),
        ];
    }

    /** Tab: Advanced (F-2.2.2-019..023; 0 = disabled per D-23). @return array<int, mixed> */
    public static function advancedSchema(): array
    {
        return [
            Forms\Components\TextInput::make('autodelete_unconfirmed_days')
                ->label(__('lists.form.autodelete_unconfirmed_days'))
                ->helperText(__('lists.form.zero_disables'))
                ->numeric()
                ->minValue(0)
                ->default(0),
            Forms\Components\TextInput::make('autoremove_bounces_count')
                ->label(__('lists.form.autoremove_bounces_count'))
                ->helperText(__('lists.form.zero_disables'))
                ->numeric()
                ->minValue(0)
                ->default(0),
            Forms\Components\TextInput::make('optin_confirm_expiry_hours')
                ->label(__('lists.form.optin_confirm_expiry_hours'))
                ->helperText(__('lists.form.zero_disables'))
                ->numeric()
                ->minValue(0)
                ->default(0),
            Forms\Components\Textarea::make('gdpr_consent_text')
                ->label(__('lists.form.gdpr_consent_text'))
                ->rows(3),
            Forms\Components\Toggle::make('gdpr_consent_logging')
                ->label(__('lists.form.gdpr_consent_logging')),
            Forms\Components\TextInput::make('default_language')
                ->label(__('lists.form.default_language'))
                ->default('en')
                ->maxLength(5),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'subscribers as subscribers_count',
                'subscribers as confirmed_count' => fn (Builder $q) => $q->where('status', 'confirmed'),
                'subscribers as unconfirmed_count' => fn (Builder $q) => $q->where('status', 'unconfirmed'),
                'subscribers as unsubscribed_count' => fn (Builder $q) => $q->where('status', 'unsubscribed'),
                'subscribers as bounced_count' => fn (Builder $q) => $q->where('status', 'bounced'),
                'subscribers as blacklisted_count' => fn (Builder $q) => $q->where('status', 'blacklisted'),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('lists.index.name'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (EmailList $record): string => implode(', ', (array) $record->list_tags)),
                Tables\Columns\ViewColumn::make('quick_stats')
                    ->label(__('lists.index.subscribers'))
                    ->view('filament.customer.tables.columns.list-quick-stats'),
                Tables\Columns\TextColumn::make('confirmed_count')
                    ->label(__('subscribers.status.confirmed'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('unconfirmed_count')
                    ->label(__('subscribers.status.unconfirmed'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('unsubscribed_count')
                    ->label(__('subscribers.status.unsubscribed'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('bounced_count')
                    ->label(__('subscribers.status.bounced'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('opt_in')
                    ->label(__('lists.index.opt_in'))
                    ->badge()
                    ->formatStateUsing(fn (OptInType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('lists.index.created'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('lists.index.status'))
                    ->badge()
                    ->color(fn (string $state): string => $state === EmailList::STATUS_ACTIVE ? 'success' : 'danger')
                    ->formatStateUsing(fn (string $state): string => __('lists.list_status.'.$state)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('lists.index.status'))
                    ->options([
                        EmailList::STATUS_ACTIVE => __('lists.list_status.active'),
                        EmailList::STATUS_PENDING_DELETE => __('lists.list_status.pending_delete'),
                    ]),
                Tables\Filters\Filter::make('created')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label(__('lists.index.created_from')),
                        Forms\Components\DatePicker::make('created_until')
                            ->label(__('lists.index.created_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))),
                Tables\Filters\SelectFilter::make('tag')
                    ->label(__('lists.index.tag'))
                    ->options(fn (): array => EmailList::query()
                        ->pluck('list_tags')
                        ->flatten()
                        ->filter()
                        ->unique()
                        ->sort()
                        ->mapWithKeys(fn ($tag): array => [(string) $tag => (string) $tag])
                        ->all())
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, string $tag) => $q->whereJsonContains('list_tags', $tag))),
            ])
            ->actions([
                Tables\Actions\Action::make('overview')
                    ->label(__('lists.actions.overview'))
                    ->icon('heroicon-m-chart-bar')
                    ->url(fn (EmailList $record): string => self::getUrl('overview', ['record' => $record])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('subscribers')
                        ->label(__('lists.actions.subscribers'))
                        ->icon('heroicon-m-users')
                        ->url(fn (EmailList $record): string => SubscriberResource::getUrl('index', [
                            'tableFilters' => ['list_id' => ['value' => $record->id]],
                        ])),
                    Tables\Actions\Action::make('fields')
                        ->label(__('lists.actions.fields'))
                        ->icon('heroicon-m-adjustments-horizontal')
                        ->url(fn (EmailList $record): string => self::getUrl('fields', ['record' => $record])),
                    Tables\Actions\Action::make('segments')
                        ->label(__('lists.actions.segments'))
                        ->icon('heroicon-m-funnel')
                        ->url(fn (EmailList $record): string => self::getUrl('segments', ['record' => $record])),
                    // WP-08 §2.2.7/§2.2.8/§2.2.11/§2.2.12/§2.2.13 entries.
                    Tables\Actions\Action::make('import')
                        ->label(__('lists.actions.import'))
                        ->icon('heroicon-m-arrow-up-tray')
                        ->url(fn (EmailList $record): string => self::getUrl('import', ['record' => $record])),
                    self::exportAction(),
                    Tables\Actions\Action::make('forms')
                        ->label(__('lists.actions.forms'))
                        ->icon('heroicon-m-code-bracket')
                        ->url(fn (EmailList $record): string => self::getUrl('forms', ['record' => $record])),
                    Tables\Actions\Action::make('pages')
                        ->label(__('lists.actions.pages'))
                        ->icon('heroicon-m-document-text')
                        ->url(fn (EmailList $record): string => self::getUrl('pages', ['record' => $record])),
                    self::splitAction(),
                    self::syncAction(),
                    self::cleanAction(),
                    self::verifyAction(),
                    self::copyAction(),
                    self::deleteAction(),
                ]),
            ])
            // Mass list actions (F-2.2.13-007) — queued per docs/ARCHITECTURE.md rule 5;
            // per D-28, bulk actions filter to records the actor may mutate.
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    self::massExportBulkAction(),
                    self::mergeBulkAction(),
                    self::deduplicateBulkAction(),
                    self::massDeleteBulkAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Queued subscriber export for one list (spec §2.2.8): scope all /
     * filtered / segment with a column picker (standard + custom fields).
     */
    public static function exportAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('export')
            ->label(__('lists.actions.export'))
            ->icon('heroicon-m-arrow-down-tray')
            ->form(fn (EmailList $record): array => [
                Forms\Components\Radio::make('scope')
                    ->label(__('imports.export.scope'))
                    ->options([
                        ExportJob::SCOPE_ALL => __('imports.export.scopes.all'),
                        ExportJob::SCOPE_FILTERED => __('imports.export.scopes.filtered'),
                        ExportJob::SCOPE_SEGMENT => __('imports.export.scopes.segment'),
                    ])
                    ->default(ExportJob::SCOPE_ALL)
                    ->required()
                    ->live(),
                Forms\Components\Select::make('segment_id')
                    ->label(__('lists.actions.segments'))
                    ->options(fn (): array => $record->segments()->pluck('name', 'id')->all())
                    ->visible(fn (Forms\Get $get): bool => $get('scope') === ExportJob::SCOPE_SEGMENT)
                    ->required(fn (Forms\Get $get): bool => $get('scope') === ExportJob::SCOPE_SEGMENT),
                Forms\Components\Fieldset::make(__('imports.export.filters'))
                    ->visible(fn (Forms\Get $get): bool => $get('scope') === ExportJob::SCOPE_FILTERED)
                    ->schema([
                        Forms\Components\CheckboxList::make('statuses')
                            ->label(__('subscribers.index.status'))
                            ->options(SubscriberStatus::options())
                            ->columns(2),
                        Forms\Components\Select::make('source')
                            ->label(__('subscribers.index.source'))
                            ->options(SubscriberSource::options()),
                        Forms\Components\TextInput::make('search')
                            ->label(__('imports.export.search')),
                        Forms\Components\DatePicker::make('subscribed_from')
                            ->label(__('subscribers.index.subscribed_from')),
                        Forms\Components\DatePicker::make('subscribed_until')
                            ->label(__('subscribers.index.subscribed_until')),
                    ]),
                Forms\Components\CheckboxList::make('columns')
                    ->label(__('imports.export.columns_label'))
                    ->options(app(ExportColumns::class)->optionsFor($record))
                    ->default(ExportColumns::STANDARD)
                    ->required()
                    ->columns(3),
                // F-2.3.8-018 (WP-26): CSV default (round-trips through the
                // importer), XLSX via openspout.
                Forms\Components\Radio::make('format')
                    ->label(__('imports.export.format'))
                    ->options(ExportFormat::options())
                    ->default(ExportFormat::Csv->value)
                    ->inline()
                    ->required(),
            ])
            ->action(function (EmailList $record, array $data): void {
                $scope = (string) ($data['scope'] ?? ExportJob::SCOPE_ALL);

                $export = ExportJob::create([
                    'customer_id' => $record->customer_id,
                    'list_id' => $record->id,
                    'scope' => $scope,
                    'format' => ExportFormat::fromValue($data['format'] ?? null),
                    'columns' => array_values((array) $data['columns']),
                    'list_segment_id' => $scope === ExportJob::SCOPE_SEGMENT ? (int) $data['segment_id'] : null,
                    'filters' => $scope === ExportJob::SCOPE_FILTERED ? [
                        'statuses' => array_values((array) ($data['statuses'] ?? [])),
                        'source' => $data['source'] ?? null,
                        'search' => $data['search'] ?? null,
                        'subscribed_from' => $data['subscribed_from'] ?? null,
                        'subscribed_until' => $data['subscribed_until'] ?? null,
                    ] : null,
                ]);

                RunExportJob::dispatch((int) $export->id);

                Notification::make()->title(__('imports.export.queued'))->success()->send();
            });
    }

    /** Split-list tool (F-2.2.13-001): N parts by count or percentage, queued. */
    public static function splitAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('split')
            ->label(__('lists.actions.split'))
            ->icon('heroicon-m-scissors')
            ->form([
                Forms\Components\Radio::make('mode')
                    ->label(__('lists.tools.split_mode'))
                    ->options([
                        SplitListJob::MODE_COUNT => __('lists.tools.split_by_count'),
                        SplitListJob::MODE_PERCENT => __('lists.tools.split_by_percent'),
                    ])
                    ->default(SplitListJob::MODE_COUNT)
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label(fn (Forms\Get $get): string => $get('mode') === SplitListJob::MODE_PERCENT
                        ? __('lists.tools.split_percent_value')
                        : __('lists.tools.split_count_value'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(fn (Forms\Get $get): ?int => $get('mode') === SplitListJob::MODE_PERCENT ? 100 : null)
                    ->required(),
            ])
            ->action(function (EmailList $record, array $data): void {
                SplitListJob::dispatch(
                    (int) $record->customer_id,
                    (int) $record->id,
                    (string) $data['mode'],
                    (int) $data['value'],
                    (string) Str::uuid(),
                );

                Notification::make()->title(__('lists.tools.split_queued'))->success()->send();
            });
    }

    /** Sync-lists tool (F-2.2.13-002): copy/move/remove by presence, queued. */
    public static function syncAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('sync')
            ->label(__('lists.actions.sync'))
            ->icon('heroicon-m-arrows-right-left')
            ->form(fn (EmailList $record): array => [
                Forms\Components\Select::make('target_list_id')
                    ->label(__('lists.tools.sync_target'))
                    ->options(fn (): array => EmailList::query()
                        ->whereKeyNot($record->id)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->required(),
                Forms\Components\Radio::make('action')
                    ->label(__('lists.tools.sync_action'))
                    ->options([
                        SyncListsJob::ACTION_COPY => __('lists.tools.sync_copy'),
                        SyncListsJob::ACTION_MOVE => __('lists.tools.sync_move'),
                        SyncListsJob::ACTION_REMOVE => __('lists.tools.sync_remove'),
                    ])
                    ->default(SyncListsJob::ACTION_COPY)
                    ->required(),
            ])
            ->action(function (EmailList $record, array $data): void {
                SyncListsJob::dispatch(
                    (int) $record->customer_id,
                    (int) $record->id,
                    (int) $data['target_list_id'],
                    (string) $data['action'],
                    (string) Str::uuid(),
                );

                Notification::make()->title(__('lists.tools.sync_queued'))->success()->send();
            });
    }

    /**
     * Clean-list tool (F-2.2.13-004): individually selectable removal rules —
     * hard bounces, stale unconfirmed, duplicates, role addresses — queued.
     */
    public static function cleanAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('clean')
            ->label(__('lists.actions.clean'))
            ->icon('heroicon-m-sparkles')
            ->requiresConfirmation()
            ->modalHeading(__('lists.tools.clean_heading'))
            ->modalDescription(__('lists.tools.clean_description'))
            ->authorize(fn (EmailList $record): bool => Filament::auth()->user()?->can('update', $record) ?? false)
            ->form([
                Forms\Components\CheckboxList::make('rules')
                    ->label(__('lists.tools.clean_rules'))
                    ->options([
                        CleanListJob::RULE_HARD_BOUNCES => __('lists.tools.clean_hard_bounces'),
                        CleanListJob::RULE_UNCONFIRMED => __('lists.tools.clean_unconfirmed'),
                        CleanListJob::RULE_DUPLICATES => __('lists.tools.clean_duplicates'),
                        CleanListJob::RULE_ROLE_ADDRESSES => __('lists.tools.clean_role_addresses'),
                    ])
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('unconfirmed_days')
                    ->label(__('lists.tools.clean_unconfirmed_days'))
                    ->helperText(__('lists.tools.clean_unconfirmed_days_help'))
                    ->numeric()
                    ->minValue(1)
                    ->default(30)
                    ->visible(fn (Forms\Get $get): bool => in_array(
                        CleanListJob::RULE_UNCONFIRMED,
                        (array) $get('rules'),
                        true,
                    ))
                    ->required(fn (Forms\Get $get): bool => in_array(
                        CleanListJob::RULE_UNCONFIRMED,
                        (array) $get('rules'),
                        true,
                    )),
            ])
            ->action(function (EmailList $record, array $data): void {
                CleanListJob::dispatch(
                    (int) $record->customer_id,
                    (int) $record->id,
                    array_values((array) $data['rules']),
                    (int) ($data['unconfirmed_days'] ?? 0),
                    (string) Str::uuid(),
                );

                Notification::make()->title(__('lists.tools.clean_queued'))->success()->send();
            });
    }

    /**
     * Email verification for one list (F-2.2.13-005): the list-level hook on
     * the §2.9.12 registry built in WP-16 (D-12) — no second implementation.
     * Enqueues the existing VerifyBatchJob with a list target and the chosen
     * D-75 auto-action; the provider is the customer's configured one unless
     * overridden (ZeroBounce/NeverBounce/Bouncer and the rest come from the
     * registry, never a hardcoded list).
     */
    public static function verifyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('verify')
            ->label(__('lists.actions.verify'))
            ->icon('heroicon-m-check-badge')
            ->authorize(fn (EmailList $record): bool => Filament::auth()->user()?->can('update', $record) ?? false)
            ->form([
                Forms\Components\Select::make('provider')
                    ->label(__('verification.form.provider'))
                    ->options(fn (): array => app(ProviderRegistry::class)->options())
                    ->placeholder(__('verification.form.provider_default')),
                Forms\Components\Select::make('auto_action')
                    ->label(__('verification.form.auto_action'))
                    ->options([
                        VerificationJob::ACTION_TAG => __('verification.auto_action.tag'),
                        VerificationJob::ACTION_DELETE => __('verification.auto_action.delete'),
                        VerificationJob::ACTION_UNSUBSCRIBE => __('verification.auto_action.unsubscribe'),
                        VerificationJob::ACTION_BLACKLIST => __('verification.auto_action.blacklist'),
                    ])
                    ->default(VerificationJob::ACTION_TAG)
                    ->required(),
            ])
            ->action(function (EmailList $record, array $data): void {
                $customer = Filament::auth()->user();

                if (! $customer instanceof Customer) {
                    return;
                }

                $provider = ($data['provider'] ?? null) !== null && $data['provider'] !== ''
                    ? (string) $data['provider']
                    : app(Credentials::class)->providerKeyFor($customer);

                $job = VerificationJob::create([
                    'customer_id' => $record->customer_id,
                    'list_id' => $record->id,
                    'target' => VerificationJob::TARGET_LIST,
                    'provider' => $provider,
                    'auto_action' => (string) $data['auto_action'],
                    'status' => VerificationJob::STATUS_PENDING,
                ]);

                VerifyBatchJob::dispatch((int) $job->id);

                Notification::make()->title(__('lists.tools.verify_queued'))->success()->send();
            });
    }

    /**
     * Merge lists (F-2.2.13-003): fold the selected lists into one target,
     * queued. Duplicate handling and "delete the sources afterwards" are
     * explicit choices; unsubscribed/blacklisted people are never resurrected
     * (enforced in the job, not the UI).
     */
    public static function mergeBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('merge')
            ->label(__('lists.tools.merge'))
            ->icon('heroicon-m-arrows-pointing-in')
            ->requiresConfirmation()
            ->modalHeading(__('lists.tools.merge_heading'))
            ->modalDescription(__('lists.tools.merge_description'))
            ->form([
                Forms\Components\Select::make('target_list_id')
                    ->label(__('lists.tools.merge_target'))
                    ->options(fn (): array => EmailList::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
                Forms\Components\Radio::make('duplicate_handling')
                    ->label(__('lists.tools.merge_duplicates'))
                    ->options([
                        MergeListsJob::DUPLICATES_SKIP => __('lists.form.duplicates_skip'),
                        MergeListsJob::DUPLICATES_UPDATE => __('lists.form.duplicates_update'),
                    ])
                    ->default(MergeListsJob::DUPLICATES_SKIP)
                    ->required(),
                Forms\Components\Toggle::make('delete_sources')
                    ->label(__('lists.tools.merge_delete_sources'))
                    ->helperText(__('lists.tools.merge_delete_sources_help'))
                    ->default(false),
            ])
            ->action(function (Collection $records, array $data): void {
                $targetId = (int) $data['target_list_id'];
                $deleteSources = (bool) ($data['delete_sources'] ?? false);
                $user = Filament::auth()->user();

                $sourceIds = $records
                    ->filter(fn (EmailList $record): bool => (int) $record->id !== $targetId)
                    // D-28: never mutate rows the actor may not touch; deleting
                    // the sources additionally demands delete rights.
                    ->filter(fn (EmailList $record): bool => (bool) ($user?->can($deleteSources ? 'delete' : 'view', $record) ?? false))
                    ->map(fn (EmailList $record): int => (int) $record->id)
                    ->values()
                    ->all();

                if ($sourceIds === []) {
                    Notification::make()->title(__('lists.tools.merge_nothing'))->warning()->send();

                    return;
                }

                MergeListsJob::dispatch(
                    (int) ($records->first()->customer_id),
                    $sourceIds,
                    $targetId,
                    (string) $data['duplicate_handling'],
                    $deleteSources,
                    (string) Str::uuid(),
                );

                Notification::make()->title(__('lists.tools.merge_queued'))->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Deduplicate across lists (F-2.2.13-006): one row per address across the
     * selected lists, queued. The survivor is the master list's row when a
     * master is chosen, otherwise the earliest-subscribed row.
     */
    public static function deduplicateBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('deduplicate')
            ->label(__('lists.tools.dedupe'))
            ->icon('heroicon-m-funnel')
            ->requiresConfirmation()
            ->modalHeading(__('lists.tools.dedupe_heading'))
            ->modalDescription(__('lists.tools.dedupe_description'))
            ->form(fn (Collection $records): array => [
                Forms\Components\Select::make('master_list_id')
                    ->label(__('lists.tools.dedupe_master'))
                    ->helperText(__('lists.tools.dedupe_master_help'))
                    ->options($records->pluck('name', 'id')->all())
                    ->placeholder(__('lists.tools.dedupe_master_none')),
            ])
            ->action(function (Collection $records, array $data): void {
                $user = Filament::auth()->user();

                $listIds = $records
                    ->filter(fn (EmailList $record): bool => (bool) ($user?->can('delete', $record) ?? false)) // D-28
                    ->map(fn (EmailList $record): int => (int) $record->id)
                    ->values()
                    ->all();

                if (count($listIds) < 2) {
                    Notification::make()->title(__('lists.tools.dedupe_nothing'))->warning()->send();

                    return;
                }

                DeduplicateListsJob::dispatch(
                    (int) ($records->first()->customer_id),
                    $listIds,
                    ($data['master_list_id'] ?? null) !== null && $data['master_list_id'] !== ''
                        ? (int) $data['master_list_id']
                        : null,
                    (string) Str::uuid(),
                );

                Notification::make()->title(__('lists.tools.dedupe_queued'))->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** Mass export (F-2.2.13-007): one queued full export per selected list. */
    public static function massExportBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('mass_export')
            ->label(__('lists.tools.mass_export'))
            ->icon('heroicon-m-arrow-down-tray')
            ->requiresConfirmation()
            ->form([
                Forms\Components\Radio::make('format')
                    ->label(__('imports.export.format'))
                    ->options(ExportFormat::options())
                    ->default(ExportFormat::Csv->value)
                    ->inline()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $columns = app(ExportColumns::class);
                $format = ExportFormat::fromValue($data['format'] ?? null);

                foreach ($records as $record) {
                    if (! (Filament::auth()->user()?->can('view', $record) ?? false)) {
                        continue; // D-28: never mutate rows the actor can't touch
                    }

                    $export = ExportJob::create([
                        'customer_id' => $record->customer_id,
                        'list_id' => $record->id,
                        'scope' => ExportJob::SCOPE_ALL,
                        'format' => $format,
                        'columns' => array_keys($columns->optionsFor($record)),
                    ]);

                    RunExportJob::dispatch((int) $export->id);
                }

                Notification::make()->title(__('imports.export.queued'))->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Mass delete (F-2.2.13-007): typed confirmation, then the same
     * soft-delete + queued purge path as the single delete (§2.2.14).
     */
    public static function massDeleteBulkAction(): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make('mass_delete')
            ->label(__('lists.tools.mass_delete'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('lists.tools.mass_delete_heading'))
            ->modalDescription(__('lists.tools.mass_delete_description'))
            ->form([
                Forms\Components\TextInput::make('confirmation')
                    ->label(__('lists.tools.mass_delete_type_word', ['word' => __('lists.tools.mass_delete_word')]))
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ((string) $value !== __('lists.tools.mass_delete_word')) {
                            $fail(__('lists.tools.mass_delete_mismatch'));
                        }
                    }),
            ])
            ->action(function (Collection $records): void {
                foreach ($records as $record) {
                    if (! (Filament::auth()->user()?->can('delete', $record) ?? false)) {
                        continue; // D-28
                    }

                    $record->status = EmailList::STATUS_PENDING_DELETE;
                    $record->save();
                    $record->delete();

                    PurgeListJob::dispatch(
                        (int) $record->customer_id,
                        (int) $record->id,
                        (string) Str::uuid(),
                    );
                }

                Notification::make()->title(__('lists.tools.mass_delete_queued'))->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Queued list copy (F-2.2.14-001): with/without subscribers; fields,
     * segments and company/settings always travel with the copy.
     */
    public static function copyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('copy')
            ->label(__('lists.actions.copy'))
            ->icon('heroicon-m-document-duplicate')
            ->form([
                Forms\Components\Toggle::make('with_subscribers')
                    ->label(__('lists.copy.with_subscribers'))
                    ->default(false),
            ])
            ->action(function (EmailList $record, array $data): void {
                CopyListJob::dispatch(
                    (int) $record->customer_id,
                    (int) $record->id,
                    (bool) ($data['with_subscribers'] ?? false),
                    (string) Str::uuid(),
                );

                Notification::make()
                    ->title(__('lists.copy.queued'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Delete with typed confirmation + queued purge (F-2.2.14-002).
     */
    public static function deleteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('delete')
            ->label(__('lists.actions.delete'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('lists.delete.heading'))
            ->modalDescription(__('lists.delete.description'))
            ->form([
                Forms\Components\TextInput::make('confirm_name')
                    ->label(__('lists.delete.type_name'))
                    ->required()
                    ->rule(fn (EmailList $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ((string) $value !== $record->name) {
                            $fail(__('lists.delete.name_mismatch'));
                        }
                    }),
            ])
            ->authorize(fn (EmailList $record): bool => Filament::auth()->user()?->can('delete', $record) ?? false)
            ->action(function (EmailList $record): void {
                $record->status = EmailList::STATUS_PENDING_DELETE;
                $record->save();
                $record->delete(); // soft delete hides it immediately

                PurgeListJob::dispatch(
                    (int) $record->customer_id,
                    (int) $record->id,
                    (string) Str::uuid(),
                );

                Notification::make()
                    ->title(__('lists.delete.queued'))
                    ->success()
                    ->send();
            });
    }

    /** Validates a comma-separated recipient list (F-2.2.2-009/010). */
    private static function commaSeparatedEmailsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            foreach (explode(',', (string) $value) as $email) {
                $email = trim($email);

                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $fail(__('lists.form.invalid_email_list', ['email' => $email]));
                }
            }
        };
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailLists::route('/'),
            'create' => Pages\CreateEmailList::route('/create'),
            'edit' => Pages\EditEmailList::route('/{record}/edit'),
            'overview' => Pages\ListOverview::route('/{record}/overview'),
            'fields' => Pages\ManageListFields::route('/{record}/fields'),
            'segments' => Pages\ManageListSegments::route('/{record}/segments'),
            'import' => Pages\ImportSubscribers::route('/{record}/import'),
            'imports' => Pages\ListImports::route('/{record}/imports'),
            'forms' => Pages\ManageListForm::route('/{record}/forms'),
            'pages' => Pages\ManageListPages::route('/{record}/pages'),
        ];
    }
}
