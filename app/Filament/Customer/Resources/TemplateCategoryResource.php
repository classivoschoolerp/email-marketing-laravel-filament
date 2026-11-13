<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Filament\Customer\Resources\TemplateCategoryResource\Pages;
use App\Models\TemplateCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Customer template categories (spec §2.5.2): CRUD + sort order. The table is
 * tenant-scoped, so only the customer's own categories are managed here;
 * system categories appear read-only in the templates index filter.
 */
class TemplateCategoryResource extends Resource
{
    protected static ?string $model = TemplateCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $slug = 'template-categories';

    protected static ?int $navigationSort = 41;

    public static function getModelLabel(): string
    {
        return __('templates.categories.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('templates.categories.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('templates.categories.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('templates.resource.navigation_group');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('templates.fields.name'))
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('sort_order')
                ->label(__('templates.fields.sort_order'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('templates.fields.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('templates_count')
                    ->label(__('templates.fields.templates_count'))
                    ->counts('templates'),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('templates.fields.sort_order'))
                    ->sortable(),
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
            'index' => Pages\ManageTemplateCategories::route('/'),
        ];
    }
}
