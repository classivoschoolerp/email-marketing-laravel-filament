<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\DeliveryServerGroupType;
use App\Filament\Admin\Resources\DeliveryServerGroupResource\Pages;
use App\Models\Customer;
use App\Models\DeliveryServer;
use App\Models\DeliveryServerGroup;
use App\Models\Scopes\CustomerScope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery server groups / pools (spec §3.13.1 F-3.13.1-008/009, WP-33):
 * bundle servers into a pool, optionally mark it a customer's default pool
 * (ServerPicker then confines that tenant's traffic to it), and manage the
 * dedicated IPs of an IP pool. Guarded by DeliveryServerGroupPolicy
 * (servers.manage).
 */
class DeliveryServerGroupResource extends Resource
{
    protected static ?string $model = DeliveryServerGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';
    protected static ?string $navigationGroup = 'Sending Infrastructure';

    protected static ?string $slug = 'delivery-server-groups';

    protected static ?int $navigationSort = 71;

    public static function getModelLabel(): string
    {
        return __('sending.groups.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sending.groups.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('sending.groups.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('infra.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScope(CustomerScope::class);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('sending.groups.sections.pool'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('sending.groups.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('customer_id')
                        ->label(__('sending.groups.fields.customer'))
                        ->helperText(__('sending.groups.help.customer'))
                        ->options(fn (): array => Customer::query()->orderBy('email')->pluck('email', 'id')->all())
                        ->searchable()
                        ->nullable(),
                    Forms\Components\Select::make('type')
                        ->label(__('sending.groups.fields.type'))
                        ->helperText(__('sending.groups.help.type'))
                        ->options(DeliveryServerGroupType::options())
                        ->default(DeliveryServerGroupType::Pool->value)
                        ->required(),
                    Forms\Components\Textarea::make('description')
                        ->label(__('sending.groups.fields.description'))
                        ->rows(2),
                    Forms\Components\Toggle::make('enabled')
                        ->label(__('sending.groups.fields.enabled'))
                        ->default(true)
                        ->inline(false),
                    Forms\Components\Toggle::make('is_default')
                        ->label(__('sending.groups.fields.is_default'))
                        ->helperText(__('sending.groups.help.is_default'))
                        ->inline(false),
                ]),

            Forms\Components\Section::make(__('sending.groups.sections.members'))
                ->schema([
                    Forms\Components\Select::make('servers')
                        ->label(__('sending.groups.fields.servers'))
                        ->relationship('servers', 'name')
                        ->multiple()
                        ->preload()
                        ->options(fn (): array => DeliveryServer::withoutTenancy()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('sending.groups.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer.email')
                    ->label(__('sending.groups.fields.customer'))
                    ->placeholder(__('infra.labels.system')),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('sending.groups.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (DeliveryServerGroupType $state): string => $state->label()),
                Tables\Columns\TextColumn::make('servers_count')
                    ->label(__('sending.groups.fields.servers_count'))
                    ->counts('servers'),
                Tables\Columns\TextColumn::make('ips_count')
                    ->label(__('sending.groups.fields.ips_count'))
                    ->counts('ips'),
                Tables\Columns\IconColumn::make('enabled')
                    ->label(__('sending.groups.fields.enabled'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label(__('sending.groups.fields.is_default'))
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('sending.groups.fields.type'))
                    ->options(DeliveryServerGroupType::options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('ips')
                    ->label(__('sending.groups.actions.ips'))
                    ->icon('heroicon-o-globe-alt')
                    ->url(fn (DeliveryServerGroup $record): string => static::getUrl('ips', ['record' => $record])),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeliveryServerGroups::route('/'),
            'create' => Pages\CreateDeliveryServerGroup::route('/create'),
            'edit' => Pages\EditDeliveryServerGroup::route('/{record}/edit'),
            'ips' => Pages\DeliveryServerGroupIps::route('/{record}/ips'),
        ];
    }
}
