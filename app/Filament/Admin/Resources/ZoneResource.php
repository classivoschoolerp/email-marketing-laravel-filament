<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\ZoneResource\Pages;
use App\Models\Zone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * §3.23 F-3.23-005 zones/states database manager (WP-24): CRUD + CSV/JSON
 * import + the authored US/CA/AU/IN seed. This table also backs
 * /api/v1/zones. Behind `location.manage` (ZonePolicy).
 */
class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static ?string $navigationIcon = 'heroicon-o-map';
    protected static ?string $navigationGroup = 'Localization';

    protected static ?string $slug = 'zones';

    protected static ?int $navigationSort = 131;

    public static function getModelLabel(): string
    {
        return __('ops.zones.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.zones.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.zones.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.navigation.location');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('ops.zones.section'))
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('country_id')
                        ->label(__('ops.zones.fields.country'))
                        ->relationship('country', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Forms\Components\TextInput::make('code')
                        ->label(__('ops.zones.fields.code'))
                        ->helperText(__('ops.zones.help.code'))
                        ->required()
                        ->maxLength(12)
                        ->dehydrateStateUsing(fn (mixed $state): string => strtoupper((string) $state)),
                    Forms\Components\TextInput::make('name')
                        ->label(__('ops.zones.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('type')
                        ->label(__('ops.zones.fields.type'))
                        ->placeholder('state')
                        ->maxLength(32),
                    Forms\Components\Toggle::make('active')
                        ->label(__('ops.zones.fields.active'))
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('country.code')
                    ->label(__('ops.zones.fields.country'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('code')
                    ->label(__('ops.zones.fields.code'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ops.zones.fields.name'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('ops.zones.fields.type'))
                    ->toggleable(),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('ops.zones.fields.active')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country_id')
                    ->label(__('ops.zones.fields.country'))
                    ->relationship('country', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListZones::route('/'),
            'create' => Pages\CreateZone::route('/create'),
            'edit' => Pages\EditZone::route('/{record}/edit'),
        ];
    }
}
