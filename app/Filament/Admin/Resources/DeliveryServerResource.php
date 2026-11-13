<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\DeliveryServerStatus;
use App\Filament\Admin\Resources\DeliveryServerResource\Pages;
use App\Filament\Customer\Resources\DeliveryServerResource as CustomerDeliveryServerResource;
use App\Models\BounceServer;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\DeliveryServer;
use App\Models\Scopes\CustomerScope;
use App\Models\SendingDomain;
use App\Models\TrackingDomain;
use App\Sending\DriverRegistry;
use App\Sending\ServerUsage;
use App\Sending\ServerValidator;
use App\Services\Domains\SyncDkimToServers;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * ADMIN delivery servers (spec §3.13.1, WP-15): the §2.9.2 config form —
 * reused from the WP-14 customer resource including the registry-driven
 * per-driver section — plus the admin-only fields (F-3.13.1-001..004):
 * assignment to a customer / customer groups / system-only, locked flag,
 * global rotation weight (probability) & priority, and REAL association
 * selects (bounce server / sending domain / tracking domain — the WP-15
 * tables). Enable/disable, validate, copy + bulk actions (F-3.13.1-005) and
 * a per-server usage-logs page (F-3.13.1-006, F-2.9.1-003).
 *
 * Guarded by DeliveryServerPolicy (servers.manage).
 */
class DeliveryServerResource extends Resource
{
    protected static ?string $model = DeliveryServer::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static ?string $slug = 'delivery-servers';

    protected static ?int $navigationSort = 71;

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
        return __('infra.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        // Admin sees every row of every tenant plus system rows.
        return parent::getEloquentQuery()->withoutGlobalScope(CustomerScope::class);
    }

    public static function form(Form $form): Form
    {
        // Reuse the full WP-14 §2.9.2 schema (incl. driver formSchema()
        // mapping), swap its placeholder associations section for real
        // selects, and prepend the admin-only section.
        $base = CustomerDeliveryServerResource::form($form)->getComponents(withHidden: true);

        $base = array_values(array_filter(
            $base,
            static fn (Component $component): bool => ! (
                $component instanceof Section
                && $component->getHeading() === __('sending.sections.associations')
            ),
        ));

        return $form->schema([
            static::adminSection(),
            ...$base,
            static::associationsSection(),
        ]);
    }

    protected static function adminSection(): Component
    {
        return Section::make(__('infra.sections.admin'))
            ->description(__('infra.sections.admin_description'))
            ->columns(2)
            ->schema([
                // F-3.13.1-002 — customer / customer groups / system-only
                Forms\Components\Select::make('customer_id')
                    ->label(__('infra.fields.customer'))
                    ->helperText(__('infra.help.customer_assignment'))
                    ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                    ->searchable()
                    ->nullable()
                    ->live(),
                Forms\Components\Select::make('customer_groups')
                    ->label(__('infra.fields.customer_groups'))
                    ->helperText(__('infra.help.customer_groups'))
                    ->multiple()
                    ->options(fn (): array => CustomerGroup::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (Get $get): bool => blank($get('customer_id'))),

                // F-3.13.1-003 — locked flag
                Forms\Components\Toggle::make('locked')
                    ->label(__('infra.fields.locked'))
                    ->helperText(__('infra.help.locked')),

                // F-3.13.1-004 — global rotation weight & priority (weight is
                // the probability field in the quotas section below).
                Forms\Components\TextInput::make('priority')
                    ->label(__('infra.fields.priority'))
                    ->helperText(__('infra.help.priority'))
                    ->numeric()->integer()->minValue(0)->default(0)->required(),
            ]);
    }

    protected static function associationsSection(): Component
    {
        return Section::make(__('sending.sections.associations'))
            ->columns(3)
            ->schema([
                Forms\Components\Select::make('bounce_server_id')
                    ->label(__('sending.fields.bounce_server'))
                    ->options(fn (): array => BounceServer::withoutTenancy()
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->nullable(),
                Forms\Components\Select::make('sending_domain_id')
                    ->label(__('sending.fields.sending_domain'))
                    ->helperText(__('infra.help.sending_domain_link'))
                    ->options(fn (): array => SendingDomain::withoutTenancy()
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->nullable(),
                Forms\Components\Select::make('tracking_domain_id')
                    ->label(__('sending.fields.tracking_domain'))
                    ->options(fn (): array => TrackingDomain::withoutTenancy()
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->nullable(),
            ]);
    }

    /**
     * DKIM handoff on link (F-2.9.7-007): when the saved server points at a
     * sending domain, copy the domain's signing material onto the server —
     * MimeBuilder signs from the server's dkim_* columns.
     */
    public static function syncDkimFromDomain(DeliveryServer $server): void
    {
        if ($server->sending_domain_id === null) {
            return;
        }

        $domain = SendingDomain::withoutTenancy()->find($server->sending_domain_id);

        if ($domain !== null) {
            app(SyncDkimToServers::class)->applyTo($server, $domain);
        }
    }

    public static function table(Table $table): Table
    {
        $usage = app(ServerUsage::class);
        $registry = app(DriverRegistry::class);

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('sending.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('infra.fields.customer'))
                    ->placeholder(__('infra.labels.system'))
                    ->toggleable(),
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
                    ->state(fn (DeliveryServer $record): string => CustomerDeliveryServerResource::usageBar(
                        $usage->usedThisHour($record),
                        $record->hourly_quota,
                    )),
                Tables\Columns\TextColumn::make('daily_usage')
                    ->label(__('sending.fields.daily_usage'))
                    ->state(fn (DeliveryServer $record): string => CustomerDeliveryServerResource::usageBar(
                        $usage->usedToday($record),
                        $record->daily_quota,
                    )),
                Tables\Columns\TextColumn::make('probability')
                    ->label(__('sending.fields.probability'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('priority')
                    ->label(__('infra.fields.priority'))
                    ->sortable()
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
                Tables\Filters\TernaryFilter::make('system')
                    ->label(__('infra.filters.system_only'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('customer_id'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('customer_id'),
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::validateAction(),
                static::toggleAction(),
                static::copyAction(),
                Tables\Actions\Action::make('usage_logs')
                    ->label(__('infra.actions.usage_logs'))
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (DeliveryServer $record): string => static::getUrl('usage', ['record' => $record])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('enable')
                    ->label(__('sending.actions.enable'))
                    ->icon('heroicon-o-play-circle')
                    ->action(fn (Collection $records) => $records->each(
                        fn (DeliveryServer $server) => $server->forceFill(['status' => DeliveryServerStatus::Active])->save(),
                    ))
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('disable')
                    ->label(__('sending.actions.disable'))
                    ->icon('heroicon-o-pause-circle')
                    ->action(fn (Collection $records) => $records->each(
                        fn (DeliveryServer $server) => $server->forceFill(['status' => DeliveryServerStatus::Disabled])->save(),
                    ))
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    /** F-3.13.1-005 — validate: confirmation email through the real driver. */
    public static function validateAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('validate')
            ->label(__('sending.actions.validate'))
            ->icon('heroicon-o-check-badge')
            ->requiresConfirmation()
            ->modalDescription(__('sending.actions.validate_description'))
            ->action(function (DeliveryServer $record): void {
                $result = app(ServerValidator::class)->begin($record);

                if ($result->ok) {
                    Notification::make()->title(__('sending.notifications.validation_sent'))->success()->send();
                } else {
                    Notification::make()
                        ->title(__('sending.notifications.validation_failed'))
                        ->body((string) $result->error)
                        ->danger()
                        ->send();
                }
            });
    }

    public static function toggleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggle')
            ->label(fn (DeliveryServer $record): string => $record->status === DeliveryServerStatus::Active || $record->status === DeliveryServerStatus::HourlyQuotaReached
                ? __('sending.actions.disable')
                : __('sending.actions.enable'))
            ->icon(fn (DeliveryServer $record): string => $record->isActive() ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
            ->action(function (DeliveryServer $record): void {
                $disable = $record->status === DeliveryServerStatus::Active
                    || $record->status === DeliveryServerStatus::HourlyQuotaReached;

                $record->forceFill([
                    'status' => $disable ? DeliveryServerStatus::Disabled : DeliveryServerStatus::Active,
                ])->save();

                Notification::make()
                    ->title($disable ? __('sending.notifications.disabled') : __('sending.notifications.enabled'))
                    ->success()
                    ->send();
            });
    }

    /** Copy — admin copies keep the owner/assignment, start inactive. */
    public static function copyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('copy')
            ->label(__('sending.actions.copy'))
            ->icon('heroicon-o-document-duplicate')
            ->action(function (DeliveryServer $record): void {
                $copy = $record->replicate(['uid', 'validation_token', 'validated_at', 'last_used_at']);
                $copy->uid = null; // regenerated by the creating hook
                $copy->name = $record->name.' '.__('sending.labels.copy_suffix');
                $copy->status = DeliveryServerStatus::Inactive;
                $copy->save();

                Notification::make()->title(__('sending.notifications.copied'))->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryServers::route('/'),
            'create' => Pages\CreateDeliveryServer::route('/create'),
            'edit' => Pages\EditDeliveryServer::route('/{record}/edit'),
            'usage' => Pages\DeliveryServerUsageLogs::route('/{record}/usage'),
        ];
    }
}
