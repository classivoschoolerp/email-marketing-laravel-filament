<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Enums\DeliveryServerStatus;
use App\Enums\QuotaExceededAction;
use App\Filament\Customer\Resources\DeliveryServerResource\Pages;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\EmailList;
use App\Sending\DriverRegistry;
use App\Sending\ServerUsage;
use App\Services\Sending\DeliveryServerQuota;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Customer delivery servers (spec §2.9.1 basics + §2.9.2 add/edit, WP-14).
 *
 * The per-type config section is registry-driven: every SendingDriver
 * declares a declarative formSchema() (docs/ARCHITECTURE.md rule 1 — drivers stay
 * UI-free) which this resource maps to Filament components. Type options are
 * whitelist-filtered per customer (D-21 chain via DeliveryServerQuota);
 * system/group-assigned rows appear read-only (policy); locked rows are
 * read-only too. The admin-side resource is WP-15.
 */
class DeliveryServerResource extends Resource
{
    protected static ?string $model = DeliveryServer::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $slug = 'delivery-servers';

    protected static ?int $navigationSort = 60;

    public static function getModelLabel(): string
    {
        return __('sending.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sending.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sending.resource.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('sending.resource.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        // Own rows + group-assigned system rows, read-only for the latter.
        return parent::getEloquentQuery()->browsable();
    }

    /** @return array<string, string> driver key => label, filtered by the whitelist chain */
    public static function allowedTypeOptions(): array
    {
        $registry = app(DriverRegistry::class);
        $customer = auth('customer')->user();

        $keys = $registry->keys();

        if ($customer instanceof Customer) {
            $keys = app(DeliveryServerQuota::class)->allowedTypes($customer, $keys);
        }

        $options = [];

        foreach ($keys as $key) {
            $options[$key] = $registry->driver($key)->label();
        }

        return $options;
    }

    /**
     * F-2.9.2-038 assignable lists — tenant-scoped by the global CustomerScope.
     *
     * @return array<int, string>
     */
    public static function assignableListOptions(): array
    {
        return EmailList::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * F-2.9.2-038 assignable campaigns — tenant-scoped by the global CustomerScope.
     *
     * @return array<int, string>
     */
    public static function assignableCampaignOptions(): array
    {
        return Campaign::query()->orderByDesc('id')->pluck('name', 'id')->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('sending.sections.general'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('sending.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('type')
                        ->label(__('sending.fields.type'))
                        ->options(fn (): array => static::allowedTypeOptions())
                        ->in(fn (): array => array_keys(static::allowedTypeOptions()))
                        ->required()
                        ->live()
                        ->disabledOn('edit'),
                ]),

            Forms\Components\Section::make(__('sending.sections.driver_config'))
                ->description(__('sending.sections.driver_config_description'))
                ->columns(2)
                ->schema(fn (Get $get): array => static::driverSchemaFor($get('type')))
                ->visible(fn (Get $get): bool => filled($get('type'))),

            Forms\Components\Section::make(__('sending.sections.sender'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('from_email')
                        ->label(__('sending.fields.from_email'))
                        ->email()
                        ->required(),
                    Forms\Components\TextInput::make('from_name')
                        ->label(__('sending.fields.from_name'))
                        ->maxLength(255),
                    Forms\Components\TextInput::make('reply_to_email')
                        ->label(__('sending.fields.reply_to_email'))
                        ->email(),
                    Forms\Components\Toggle::make('force_from')
                        ->label(__('sending.fields.force_from'))
                        ->helperText(__('sending.help.force_from')),
                    Forms\Components\Toggle::make('force_reply_to')
                        ->label(__('sending.fields.force_reply_to')),
                ]),

            Forms\Components\Section::make(__('sending.sections.quotas'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('probability')
                        ->label(__('sending.fields.probability'))
                        ->helperText(__('sending.help.probability'))
                        ->numeric()->integer()->minValue(0)->maxValue(100)
                        ->default(100)
                        ->required(),
                    Forms\Components\TextInput::make('hourly_quota')
                        ->label(__('sending.fields.hourly_quota'))
                        ->helperText(__('sending.help.zero_unlimited'))
                        ->numeric()->integer()->minValue(0)->default(0)->required(),
                    Forms\Components\TextInput::make('daily_quota')
                        ->label(__('sending.fields.daily_quota'))
                        ->helperText(__('sending.help.zero_unlimited'))
                        ->numeric()->integer()->minValue(0)->default(0)->required(),
                    Forms\Components\TextInput::make('monthly_quota')
                        ->label(__('sending.fields.monthly_quota'))
                        ->helperText(__('sending.help.zero_unlimited'))
                        ->numeric()->integer()->minValue(0)->default(0)->required(),
                    Forms\Components\Select::make('quota_exceeded_action')
                        ->label(__('sending.fields.quota_exceeded_action'))
                        ->options([
                            QuotaExceededAction::Pause->value => __('sending.quota_action.pause'),
                            QuotaExceededAction::MoveToNext->value => __('sending.quota_action.next'),
                        ])
                        ->default(QuotaExceededAction::Pause->value)
                        ->required(),
                ]),

            Forms\Components\Section::make(__('sending.sections.connection'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('max_connection_messages')
                        ->label(__('sending.fields.max_connection_messages'))
                        ->helperText(__('sending.help.zero_unlimited'))
                        ->numeric()->integer()->minValue(0)->default(0)->required(),
                    Forms\Components\TextInput::make('connection_timeout')
                        ->label(__('sending.fields.connection_timeout'))
                        ->numeric()->integer()->minValue(0)->default(30)->required(),
                    Forms\Components\Toggle::make('keep_alive')
                        ->label(__('sending.fields.keep_alive'))
                        ->default(true)
                        ->inline(false),
                ]),

            Forms\Components\Section::make(__('sending.sections.headers'))
                ->schema([
                    Forms\Components\KeyValue::make('custom_headers')
                        ->label(__('sending.fields.custom_headers'))
                        ->keyLabel(__('sending.fields.header_name'))
                        ->valueLabel(__('sending.fields.header_value'))
                        ->nullable(),
                ]),

            Forms\Components\Section::make(__('sending.sections.associations'))
                ->description(__('sending.help.associations_pending'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('bounce_server_id')
                        ->label(__('sending.fields.bounce_server'))
                        ->numeric()->integer()->minValue(1)->nullable(),
                    Forms\Components\TextInput::make('tracking_domain_id')
                        ->label(__('sending.fields.tracking_domain'))
                        ->numeric()->integer()->minValue(1)->nullable(),
                    Forms\Components\TextInput::make('sending_domain_id')
                        ->label(__('sending.fields.sending_domain'))
                        ->numeric()->integer()->minValue(1)->nullable(),
                    Forms\Components\TextInput::make('warmup_plan_id')
                        ->label(__('sending.fields.warmup_plan'))
                        ->helperText(__('sending.help.warmup_pending'))
                        ->numeric()->integer()->minValue(1)->nullable(),
                ]),

            // F-2.9.2-038 — narrow this server to specific lists/campaigns.
            // Empty = no restriction (sub_user_list_access convention).
            Forms\Components\Section::make(__('sending.sections.assignment'))
                ->description(__('sending.help.assignment'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('assigned_list_ids')
                        ->label(__('sending.fields.assigned_lists'))
                        ->helperText(__('sending.help.assignment_empty'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => static::assignableListOptions())
                        // Tenancy on the wire, not just in the picker: only
                        // ids the current tenant owns survive validation.
                        ->nestedRecursiveRules([fn (): In => Rule::in(array_keys(static::assignableListOptions()))]),
                    Forms\Components\Select::make('assigned_campaign_ids')
                        ->label(__('sending.fields.assigned_campaigns'))
                        ->helperText(__('sending.help.assignment_empty'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => static::assignableCampaignOptions())
                        ->nestedRecursiveRules([fn (): In => Rule::in(array_keys(static::assignableCampaignOptions()))]),
                ]),

            Forms\Components\Section::make(__('sending.sections.dkim'))
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('dkim_signing_enabled')
                        ->label(__('sending.fields.dkim_signing_enabled'))
                        ->live(),
                    Forms\Components\TextInput::make('dkim_selector')
                        ->label(__('sending.fields.dkim_selector'))
                        ->maxLength(64)
                        ->visible(fn (Get $get): bool => (bool) $get('dkim_signing_enabled')),
                    Forms\Components\Textarea::make('dkim_private_key')
                        ->label(__('sending.fields.dkim_private_key'))
                        ->rows(6)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => (bool) $get('dkim_signing_enabled')),
                ]),
        ]);
    }

    /**
     * Map a driver's declarative formSchema() specs to Filament components
     * (registry-driven per-type config, F-2.9.2-001..019).
     *
     * @return list<Component>
     */
    public static function driverSchemaFor(?string $type): array
    {
        $registry = app(DriverRegistry::class);

        if ($type === null || $type === '' || ! $registry->has($type)) {
            return [];
        }

        $components = [];

        foreach ($registry->driver($type)->formSchema() as $spec) {
            $components[] = static::componentFromSpec($spec);
        }

        return $components;
    }

    /** @param  array<string, mixed>  $spec */
    protected static function componentFromSpec(array $spec): Component
    {
        $name = (string) $spec['name'];
        $label = __((string) $spec['label']);

        $component = match ((string) $spec['type']) {
            'password' => Forms\Components\TextInput::make($name)->label($label)->password()->revealable()
                // Never wipe a stored secret when the field is left blank on edit.
                ->dehydrateStateUsing(fn ($state) => $state)
                ->dehydrated(fn ($state): bool => filled($state)),
            'number' => Forms\Components\TextInput::make($name)->label($label)->numeric()->integer(),
            'toggle' => Forms\Components\Toggle::make($name)->label($label),
            'select' => Forms\Components\Select::make($name)->label($label)
                ->options(array_map(
                    static fn (string $optionLabel): string => str_contains($optionLabel, '.') ? __($optionLabel) : $optionLabel,
                    (array) $spec['options'],
                )),
            default => Forms\Components\TextInput::make($name)->label($label)->maxLength(1000),
        };

        if (! empty($spec['required'])) {
            // Password fields stay optional on edit (secret already stored).
            $component = (string) $spec['type'] === 'password'
                ? $component->required(fn (string $operation): bool => $operation === 'create')
                : $component->required();
        }

        if (($spec['default'] ?? null) !== null) {
            $component = $component->default($spec['default']);
        }

        if (($spec['help'] ?? null) !== null) {
            $component = $component->helperText(__((string) $spec['help']));
        }

        return $component;
    }

    public static function table(Table $table): Table
    {
        $usage = app(ServerUsage::class);
        $registry = app(DriverRegistry::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('sending.fields.name'))
                    ->description(fn (DeliveryServer $record): ?string => $record->isSystem() ? __('sending.labels.system_server') : null)
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('sending.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $registry->has($state) ? $registry->driver($state)->label() : $state),
                Tables\Columns\TextColumn::make('hostname')
                    ->label(__('sending.fields.hostname'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('from_email')
                    ->label(__('sending.fields.from_email'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('sending.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (DeliveryServerStatus $state): string => $state->label())
                    ->color(fn (DeliveryServerStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('hourly_usage')
                    ->label(__('sending.fields.hourly_usage'))
                    ->state(fn (DeliveryServer $record): string => static::usageBar(
                        $usage->usedThisHour($record),
                        $record->hourly_quota,
                    )),
                Tables\Columns\TextColumn::make('daily_usage')
                    ->label(__('sending.fields.daily_usage'))
                    ->state(fn (DeliveryServer $record): string => static::usageBar(
                        $usage->usedToday($record),
                        $record->daily_quota,
                    )),
                Tables\Columns\TextColumn::make('probability')
                    ->label(__('sending.fields.probability'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('locked')
                    ->label(__('sending.fields.locked'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->falseIcon('heroicon-o-lock-open'),
                Tables\Columns\TextColumn::make('last_used_at')
                    ->label(__('sending.fields.last_used'))
                    ->since()
                    ->placeholder(__('sending.labels.never')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('sending.fields.type'))
                    ->options(fn (): array => $registry->options()),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('sending.fields.status'))
                    ->options(collect(DeliveryServerStatus::cases())
                        ->mapWithKeys(fn (DeliveryServerStatus $s): array => [$s->value => $s->label()])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Pages\ListDeliveryServers::validateAction(),
                Pages\ListDeliveryServers::toggleAction(),
                Pages\ListDeliveryServers::copyAction(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    /** "used / quota (pct%)" text bar; quota 0 = unlimited (D-23). */
    public static function usageBar(int $used, int $quota): string
    {
        if ($quota <= 0) {
            return $used.' / '.__('sending.labels.unlimited');
        }

        $pct = min(100, (int) round($used / $quota * 100));
        $filled = (int) round($pct / 10);

        return sprintf('%d / %d  %s%s %d%%', $used, $quota, str_repeat('█', $filled), str_repeat('░', 10 - $filled), $pct);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryServers::route('/'),
            'create' => Pages\CreateDeliveryServer::route('/create'),
            'edit' => Pages\EditDeliveryServer::route('/{record}/edit'),
        ];
    }
}
