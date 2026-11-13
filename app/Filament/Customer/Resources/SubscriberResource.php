<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Enums\VerificationStatus;
use App\Filament\Customer\Resources\SubscriberResource\Pages;
use App\Filament\Customer\Support\ListFieldInputs;
use App\Jobs\Subscribers\BulkSubscriberActionJob;
use App\Jobs\Subscribers\ExportSelectedSubscribersJob;
use App\Jobs\Verification\VerifyBatchJob;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use App\Models\Subscriber;
use App\Models\SubscriberFieldValue;
use App\Models\SubscriberTag;
use App\Models\VerificationJob;
use App\Services\Lists\FieldValueService;
use App\Services\Lists\ListMailer;
use App\Services\Subscribers\EngagementQuery;
use App\Services\Subscribers\SubscriberSearchIndexer;
use App\Services\Verification\Credentials;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Subscribers CRUD (spec §2.2.4–§2.2.6): dynamic custom-field columns,
 * status filters, search across email and field values, row actions, and
 * queued bulk actions (docs/ARCHITECTURE.md rule 5 — nothing bulk runs synchronously).
 */
class SubscriberResource extends Resource
{
    protected static ?string $model = Subscriber::class;

    protected static ?string $slug = 'subscribers';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'email';

    /** Confirmation resends allowed per subscriber inside RESEND_DECAY_SECONDS (F-2.2.4-006). */
    public const RESEND_MAX_ATTEMPTS = 3;

    public const RESEND_DECAY_SECONDS = 3600;

    public static function getNavigationGroup(): ?string
    {
        return __('lists.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('subscribers.nav_label');
    }

    public static function getModelLabel(): string
    {
        return __('subscribers.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('subscribers.model_label_plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('list');

        // Defence in depth on top of CustomerScope: Livewire update requests
        // run without the request tenant, so pin from the authenticated
        // customer, never from UI state (docs/ARCHITECTURE.md rule 3).
        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('subscribers.customer_id', $user->id);
        }

        return $query;
    }

    /** Tenant-pinned list options for selects and filters. @return array<int, string> */
    /** The signed-in tenant's id (0 when an operator is impersonating panel-wide). */
    public static function currentCustomerId(): int
    {
        $user = Filament::auth()->user();

        return $user instanceof Customer ? (int) $user->id : 0;
    }

    public static function listOptions(): array
    {
        $query = EmailList::query()->orderBy('name');

        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query->pluck('name', 'id')->all();
    }

    /**
     * Distinct tag names owned by the signed-in tenant (F-2.2.5-004), for the
     * index filter and the profile's tag suggestions. Pinned to the
     * authenticated customer for the same reason getEloquentQuery() is.
     *
     * @return array<string, string> name => name
     */
    public static function tagOptions(): array
    {
        $query = SubscriberTag::query()->select('name')->distinct()->orderBy('name');

        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query->pluck('name', 'name')->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('subscribers.form.section_subscriber'))->schema([
                Forms\Components\Select::make('list_id')
                    ->label(__('subscribers.form.list'))
                    ->options(self::listOptions())
                    ->required()
                    ->live()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (string $operation): bool => $operation !== 'edit'),
                Forms\Components\TextInput::make('email')
                    ->label(__('subscribers.form.email'))
                    ->email()
                    ->required()
                    ->maxLength(191)
                    // Duplicate detection against (list_id, email) with merge
                    // option (F-2.2.6-004).
                    ->rule(fn (Forms\Get $get, ?Subscriber $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        $exists = Subscriber::query()
                            ->where('list_id', $get('list_id'))
                            ->where('email', mb_strtolower(trim((string) $value)))
                            ->when($record !== null, fn (Builder $query) => $query->whereKeyNot($record->id))
                            ->exists();

                        if ($exists && ! $get('merge_duplicate')) {
                            $fail(__('subscribers.form.duplicate_error'));
                        }
                    }),
                Forms\Components\Toggle::make('merge_duplicate')
                    ->label(__('subscribers.form.merge_duplicate'))
                    ->helperText(__('subscribers.form.merge_duplicate_help'))
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->live(),
                // Status selector (F-2.2.6-002).
                Forms\Components\Select::make('status')
                    ->label(__('subscribers.form.status'))
                    ->options(SubscriberStatus::options())
                    ->default(SubscriberStatus::Unconfirmed->value)
                    ->required()
                    ->live(),
                Forms\Components\Select::make('source')
                    ->label(__('subscribers.form.source'))
                    ->options(SubscriberSource::options())
                    ->default(SubscriberSource::Manual->value)
                    ->required(),
                // Skip/force confirmation (F-2.2.6-003): with "skip" the
                // subscriber is stored confirmed regardless of double opt-in;
                // left off, an unconfirmed subscriber awaits the confirmation
                // email (sent by the forms work package).
                Forms\Components\Toggle::make('skip_confirmation')
                    ->label(__('subscribers.form.skip_confirmation'))
                    ->helperText(__('subscribers.form.skip_confirmation_help'))
                    ->visible(fn (string $operation, Forms\Get $get): bool => $operation === 'create'
                        && $get('status') === SubscriberStatus::Unconfirmed->value),
            ])->columns(2),
            // Every custom field rendered by its type (F-2.2.6-001).
            Forms\Components\Section::make(__('subscribers.form.section_fields'))
                ->schema(fn (Forms\Get $get): array => self::customFieldSchema($get('list_id')))
                ->visible(fn (Forms\Get $get): bool => filled($get('list_id'))
                    && self::customFieldSchema($get('list_id')) !== [])
                ->columns(2),
        ]);
    }

    /** @return array<int, mixed> */
    public static function customFieldSchema(mixed $listId): array
    {
        if (! filled($listId)) {
            return [];
        }

        $query = ListField::query()->where('list_id', (int) $listId)->orderBy('sort_order');

        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query->with('options')
            ->get()
            ->map(fn (ListField $field) => ListFieldInputs::make($field))
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('fieldValues'))
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label(__('subscribers.index.email'))
                    ->sortable()
                    // Search covers email AND custom field values (F-2.2.4-003).
                    // WP-42 (§0.1 F-0.1-008): routed through the search-engine
                    // seam — Meilisearch/Typesense when one is configured, the
                    // original LIKE query when not. The engine returns ids
                    // only, so the tenancy scope still fences the result.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $user = Filament::auth()->user();

                        return app(SubscriberSearchIndexer::class)->apply(
                            $query,
                            $search,
                            $user instanceof Customer ? (int) $user->id : null,
                        );
                    }),
                Tables\Columns\TextColumn::make('list.name')
                    ->label(__('subscribers.index.list'))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('subscribers.index.status'))
                    ->badge()
                    ->formatStateUsing(fn (SubscriberStatus $state): string => $state->label())
                    ->color(fn (SubscriberStatus $state): string => $state->color())
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label(__('subscribers.index.source'))
                    ->formatStateUsing(fn (SubscriberSource $state): string => $state->label())
                    ->sortable()
                    ->toggleable(),
                // WP-16 (§2.9.12 F-2.9.12-006): verification result tag.
                Tables\Columns\TextColumn::make('verification_status')
                    ->label(__('verification.subscribers.verification_status'))
                    ->badge()
                    ->formatStateUsing(fn (VerificationStatus $state): string => $state->label())
                    ->color(fn (VerificationStatus $state): string => $state->color())
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                // Tags (F-2.2.5-004): hidden by default so the default table
                // stays one query per page; toggling it eager-loads.
                Tables\Columns\TextColumn::make('tags.name')
                    ->label(__('subscribers.tags.label'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('subscribed_at')
                    ->label(__('subscribers.index.subscribed_at'))
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip')
                    ->label(__('subscribers.index.ip'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label(__('subscribers.index.confirmed_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('unsubscribed_at')
                    ->label(__('subscribers.index.unsubscribed_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_open_at')
                    ->label(__('subscribers.index.last_open_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_click_at')
                    ->label(__('subscribers.index.last_click_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ...self::customFieldColumns($table),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('list_id')
                    ->label(__('subscribers.index.list'))
                    ->options(fn (): array => self::listOptions()),
                // Status filter with every §2.2.4 status (F-2.2.4-002).
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('subscribers.index.status'))
                    ->options(SubscriberStatus::options())
                    ->multiple(),
                Tables\Filters\SelectFilter::make('source')
                    ->label(__('subscribers.index.source'))
                    ->options(SubscriberSource::options()),
                // Tag filter (F-2.2.5-004): ANY of the chosen tags. The
                // subquery is tenant-pinned as well as scope-fenced, so a
                // forged tag name can never reach another tenant's rows.
                Tables\Filters\SelectFilter::make('tags')
                    ->label(__('subscribers.tags.filter'))
                    ->multiple()
                    ->options(fn (): array => self::tagOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $names = array_values(array_filter((array) ($data['values'] ?? [])));

                        if ($names === []) {
                            return $query;
                        }

                        $customerId = self::currentCustomerId();

                        return $query->whereHas('tags', function (Builder $tags) use ($names, $customerId): Builder {
                            if ($customerId > 0) {
                                $tags->where('subscriber_tags.customer_id', $customerId);
                            }

                            return $tags->whereIn('subscriber_tags.name', $names);
                        });
                    }),
                Tables\Filters\Filter::make('subscribed')
                    ->form([
                        Forms\Components\DatePicker::make('subscribed_from')
                            ->label(__('subscribers.index.subscribed_from')),
                        Forms\Components\DatePicker::make('subscribed_until')
                            ->label(__('subscribers.index.subscribed_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['subscribed_from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('subscribed_at', '>=', $date))
                        ->when($data['subscribed_until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('subscribed_at', '<=', $date))),
                // Engagement search over the WP-09 tracking tables
                // (F-2.2.4-003): opened / clicked / did not open / did not
                // click, optionally bounded to the last N days.
                Tables\Filters\Filter::make('engagement')
                    ->form([
                        Forms\Components\Select::make('mode')
                            ->label(__('subscribers.engagement.label'))
                            ->options(EngagementQuery::modeOptions())
                            ->native(false),
                        Forms\Components\TextInput::make('within_days')
                            ->label(__('subscribers.engagement.within_days'))
                            ->helperText(__('subscribers.engagement.within_days_help'))
                            ->numeric()
                            ->minValue(1),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $mode = (string) ($data['mode'] ?? '');

                        if ($mode === '') {
                            return $query;
                        }

                        app(EngagementQuery::class)->applyMode(
                            $query,
                            $mode,
                            self::currentCustomerId(),
                            (int) ($data['within_days'] ?? 0),
                        );

                        return $query;
                    })
                    ->indicateUsing(function (array $data): ?string {
                        $mode = (string) ($data['mode'] ?? '');

                        if ($mode === '') {
                            return null;
                        }

                        $label = EngagementQuery::modeOptions()[$mode] ?? $mode;
                        $days = (int) ($data['within_days'] ?? 0);

                        return $days > 0
                            ? __('subscribers.engagement.indicator_days', ['mode' => $label, 'days' => $days])
                            : __('subscribers.engagement.indicator', ['mode' => $label]);
                    }),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('unsubscribe')
                        ->label(__('subscribers.actions.unsubscribe'))
                        ->icon('heroicon-m-minus-circle')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(fn (Subscriber $record): bool => $record->status !== SubscriberStatus::Unsubscribed)
                        ->action(fn (Subscriber $record) => self::transition($record, SubscriberStatus::Unsubscribed)),
                    Tables\Actions\Action::make('confirm')
                        ->label(__('subscribers.actions.confirm'))
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->visible(fn (Subscriber $record): bool => $record->status === SubscriberStatus::Unconfirmed)
                        ->action(fn (Subscriber $record) => self::transition($record, SubscriberStatus::Confirmed)),
                    // Resend the double opt-in confirmation (F-2.2.4-006):
                    // unconfirmed rows only, rate-limited per subscriber.
                    Tables\Actions\Action::make('resend_confirmation')
                        ->label(__('subscribers.actions.resend_confirmation'))
                        ->icon('heroicon-m-envelope')
                        ->requiresConfirmation()
                        ->modalDescription(__('subscribers.actions.resend_confirmation_help'))
                        ->visible(fn (Subscriber $record): bool => $record->status === SubscriberStatus::Unconfirmed)
                        ->action(fn (Subscriber $record) => self::resendConfirmation($record)),
                    Tables\Actions\Action::make('blacklist')
                        ->label(__('subscribers.actions.blacklist'))
                        ->icon('heroicon-m-no-symbol')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (Subscriber $record): bool => $record->status !== SubscriberStatus::Blacklisted)
                        ->action(fn (Subscriber $record) => self::transition($record, SubscriberStatus::Blacklisted)),
                    Tables\Actions\Action::make('copy_to')
                        ->label(__('subscribers.actions.copy_to'))
                        ->icon('heroicon-m-document-duplicate')
                        ->form([self::targetListSelect()])
                        ->action(fn (Subscriber $record, array $data) => self::queueBulk(
                            BulkSubscriberActionJob::ACTION_COPY_TO_LIST,
                            [(int) $record->id],
                            (int) $data['target_list_id'],
                        )),
                    Tables\Actions\Action::make('move_to')
                        ->label(__('subscribers.actions.move_to'))
                        ->icon('heroicon-m-arrow-right-circle')
                        ->form([self::targetListSelect()])
                        ->action(fn (Subscriber $record, array $data) => self::queueBulk(
                            BulkSubscriberActionJob::ACTION_MOVE_TO_LIST,
                            [(int) $record->id],
                            (int) $data['target_list_id'],
                        )),
                    Tables\Actions\DeleteAction::make(),
                ]),
            ])
            // Bulk actions dispatch queued jobs (F-2.2.4-007); Filament's
            // select-all header checkbox selects every record matching the
            // current filters, not just the page (F-2.2.4-008).
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    self::bulkStatusAction('unsubscribe', BulkSubscriberActionJob::ACTION_UNSUBSCRIBE, 'heroicon-m-minus-circle'),
                    self::bulkStatusAction('confirm', BulkSubscriberActionJob::ACTION_CONFIRM, 'heroicon-m-check-circle'),
                    self::bulkStatusAction('disable', BulkSubscriberActionJob::ACTION_DISABLE, 'heroicon-m-pause-circle'),
                    self::bulkStatusAction('blacklist', BulkSubscriberActionJob::ACTION_BLACKLIST, 'heroicon-m-no-symbol'),
                    self::bulkStatusAction('unblacklist', BulkSubscriberActionJob::ACTION_UNBLACKLIST, 'heroicon-m-arrow-uturn-left'),
                    self::bulkStatusAction('delete', BulkSubscriberActionJob::ACTION_DELETE, 'heroicon-m-trash', 'danger'),
                    Tables\Actions\BulkAction::make('copy_to')
                        ->label(__('subscribers.actions.copy_to'))
                        ->icon('heroicon-m-document-duplicate')
                        ->form([self::targetListSelect()])
                        ->action(fn (Collection $records, array $data) => self::queueBulk(
                            BulkSubscriberActionJob::ACTION_COPY_TO_LIST,
                            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                            (int) $data['target_list_id'],
                        ))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('move_to')
                        ->label(__('subscribers.actions.move_to'))
                        ->icon('heroicon-m-arrow-right-circle')
                        ->form([self::targetListSelect()])
                        ->action(fn (Collection $records, array $data) => self::queueBulk(
                            BulkSubscriberActionJob::ACTION_MOVE_TO_LIST,
                            $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                            (int) $data['target_list_id'],
                        ))
                        ->deselectRecordsAfterCompletion(),
                    // WP-16 (§2.9.12 F-2.9.12-004): verify selected subscribers.
                    Tables\Actions\BulkAction::make('verify')
                        ->label(__('verification.actions.verify_selected'))
                        ->icon('heroicon-m-check-badge')
                        ->action(function (Collection $records): void {
                            $user = Filament::auth()->user();

                            if (! $user instanceof Customer) {
                                return;
                            }

                            $job = VerificationJob::create([
                                'customer_id' => $user->id,
                                'target' => VerificationJob::TARGET_SUBSCRIBERS,
                                'subscriber_ids' => $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                                'provider' => app(Credentials::class)->providerKeyFor($user),
                                'auto_action' => VerificationJob::ACTION_TAG,
                                'status' => VerificationJob::STATUS_PENDING,
                            ]);

                            VerifyBatchJob::dispatch((int) $job->id);

                            Notification::make()->title(__('verification.actions.queued'))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('export')
                        ->label(__('subscribers.actions.export_selected'))
                        ->icon('heroicon-m-arrow-down-tray')
                        ->action(function (Collection $records): void {
                            $user = Filament::auth()->user();

                            ExportSelectedSubscribersJob::dispatch(
                                $user instanceof Customer ? $user->id : 0,
                                $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                                (string) Str::uuid(),
                            );

                            Notification::make()->title(__('subscribers.actions.queued'))->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Custom-field columns (F-2.2.4-001): when the table is filtered to one
     * list, that list's show-in-table fields appear as sortable columns.
     *
     * @return list<Tables\Columns\TextColumn>
     */
    public static function customFieldColumns(Table $table): array
    {
        $livewire = $table->getLivewire();

        if (! $livewire instanceof Pages\ListSubscribers) {
            return [];
        }

        $listId = $livewire->getTableFilterState('list_id')['value'] ?? null;

        if (! filled($listId)) {
            return [];
        }

        $query = ListField::query()
            ->where('list_id', (int) $listId)
            ->where('show_in_table', true)
            ->orderBy('sort_order');

        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        $service = app(FieldValueService::class);

        return $query->get()->map(fn (ListField $field): Tables\Columns\TextColumn => Tables\Columns\TextColumn::make('field_'.$field->id)
            ->label($field->label)
            ->state(function (Subscriber $record) use ($field, $service): ?string {
                $raw = $record->fieldValues->firstWhere('list_field_id', $field->id)?->value;

                if ($raw === null) {
                    return null;
                }

                $value = $service->fromStorage($field, (string) $raw);

                if (is_array($value)) {
                    return implode(', ', $value);
                }

                if (is_bool($value)) {
                    return $value ? __('subscribers.index.yes') : __('subscribers.index.no');
                }

                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                return (string) $value;
            })
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                SubscriberFieldValue::withoutTenancy()
                    ->select('value')
                    ->whereColumn('subscriber_field_values.subscriber_id', 'subscribers.id')
                    ->where('list_field_id', $field->id)
                    ->limit(1),
                $direction,
            ))
            ->toggleable())->all();
    }

    public static function targetListSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('target_list_id')
            ->label(__('subscribers.actions.target_list'))
            ->options(fn (): array => self::listOptions())
            ->required();
    }

    /** Status transitions run through the model so cascades apply (F-2.2.2-015..018). */
    public static function transition(Subscriber $record, SubscriberStatus $status): void
    {
        $record->status = $status;
        $record->save();

        Notification::make()->title(__('subscribers.actions.done'))->success()->send();
    }

    /**
     * Re-send the double opt-in confirmation email (F-2.2.4-006).
     *
     * Reuses the §1.5 lifecycle mailer, so the message body, the from/reply-to
     * headers and the signed [CONFIRM_URL] (bounded by the list's
     * optin_confirm_expiry_hours, F-2.2.2-021) are byte-for-byte what the
     * public subscribe flow sends. Refuses on any status other than
     * Unconfirmed — a confirmed or unsubscribed person must never be re-asked.
     *
     * Rate limited per subscriber (RateLimiter, D-04 abstraction): the button
     * is a one-click mail send, so an impatient operator must not be able to
     * turn it into a mailbox flood.
     */
    public static function resendConfirmation(Subscriber $record): void
    {
        if ($record->status !== SubscriberStatus::Unconfirmed) {
            Notification::make()->title(__('subscribers.actions.resend_not_unconfirmed'))->danger()->send();

            return;
        }

        $key = 'subscriber-confirm-resend:'.$record->id;

        if (RateLimiter::tooManyAttempts($key, self::RESEND_MAX_ATTEMPTS)) {
            Notification::make()
                ->title(__('subscribers.actions.resend_throttled', [
                    'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                ]))
                ->danger()
                ->send();

            return;
        }

        /** @var EmailList|null $list */
        $list = EmailList::withoutTenancy()
            ->where('customer_id', $record->customer_id)
            ->find($record->list_id);

        if ($list === null) {
            Notification::make()->title(__('subscribers.actions.resend_no_list'))->danger()->send();

            return;
        }

        RateLimiter::hit($key, self::RESEND_DECAY_SECONDS);

        app(ListMailer::class)->sendSubscribeConfirmation($list, $record);

        Notification::make()->title(__('subscribers.actions.resent'))->success()->send();
    }

    /** @param list<int> $ids */
    public static function queueBulk(string $action, array $ids, ?int $targetListId = null): void
    {
        $user = Filament::auth()->user();

        BulkSubscriberActionJob::dispatch(
            $user instanceof Customer ? $user->id : 0,
            $action,
            $ids,
            $targetListId,
            (string) Str::uuid(),
        );

        Notification::make()->title(__('subscribers.actions.queued'))->success()->send();
    }

    private static function bulkStatusAction(string $name, string $jobAction, string $icon, string $color = 'gray'): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make($name)
            ->label(__('subscribers.actions.'.$name))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->action(fn (Collection $records) => self::queueBulk(
                $jobAction,
                $records->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ))
            ->deselectRecordsAfterCompletion();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'view' => Pages\ViewSubscriber::route('/{record}'),
            'edit' => Pages\EditSubscriber::route('/{record}/edit'),
        ];
    }
}
