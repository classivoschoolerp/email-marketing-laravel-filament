<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\CountryResource\Pages;
use App\Models\Country;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * §3.23 F-3.23-004 countries database manager (WP-24): CRUD + CSV/JSON
 * import + the built-in ISO 3166-1 dataset. Behind `location.manage`
 * (CountryPolicy).
 */
class CountryResource extends Resource
{
    protected static ?string $model = Country::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';
    protected static ?string $navigationGroup = 'Localization';

    protected static ?string $slug = 'countries';

    protected static ?int $navigationSort = 130;

    public static function getModelLabel(): string
    {
        return __('ops.countries.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.countries.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.countries.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.navigation.location');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('ops.countries.section'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label(__('ops.countries.fields.code'))
                        ->helperText(__('ops.countries.help.code'))
                        ->required()
                        ->length(2)
                        ->alpha()
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn (mixed $state): string => strtoupper((string) $state)),
                    Forms\Components\TextInput::make('name')
                        ->label(__('ops.countries.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('code3')
                        ->label(__('ops.countries.fields.code3'))
                        ->maxLength(3)
                        ->dehydrateStateUsing(fn (mixed $state): ?string => $state === null || $state === '' ? null : strtoupper((string) $state)),
                    Forms\Components\TextInput::make('numeric_code')
                        ->label(__('ops.countries.fields.numeric_code'))
                        ->maxLength(3),
                    Forms\Components\TextInput::make('phone_prefix')
                        ->label(__('ops.countries.fields.phone_prefix'))
                        ->maxLength(8)
                        ->placeholder('+1'),
                    Forms\Components\Toggle::make('active')
                        ->label(__('ops.countries.fields.active'))
                        ->helperText(__('ops.countries.help.active'))
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label(__('ops.countries.fields.code'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ops.countries.fields.name'))
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('code3')
                    ->label(__('ops.countries.fields.code3'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('phone_prefix')
                    ->label(__('ops.countries.fields.phone_prefix'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('zones_count')
                    ->label(__('ops.countries.fields.zones_count'))
                    ->counts('zones'),
                Tables\Columns\ToggleColumn::make('active')
                    ->label(__('ops.countries.fields.active')),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label(__('ops.countries.fields.active')),
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
            'index' => Pages\ListCountries::route('/'),
            'create' => Pages\CreateCountry::route('/create'),
            'edit' => Pages\EditCountry::route('/{record}/edit'),
        ];
    }
}
