<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources;

use App\Filament\Customer\Resources\SpintaxSnippetResource\Pages;
use App\Models\Customer;
use App\Models\SpintaxSnippet;
use App\Services\Content\Spintax;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Saved Spintax snippet library (spec §2.3.3 F-2.3.3-023): CRUD for
 * reusable `{a|b|c}` fragments, insertable from the campaign content step.
 */
class SpintaxSnippetResource extends Resource
{
    protected static ?string $model = SpintaxSnippet::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $slug = 'spintax-snippets';

    protected static ?int $navigationSort = 22;

    public static function getModelLabel(): string
    {
        return __('campaigns.snippets.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('campaigns.snippets.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('campaigns.snippets.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('campaigns.resource.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = Filament::auth()->user();

        if ($user instanceof Customer) {
            $query->where('customer_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label(__('campaigns.snippets.name'))
                ->required()
                ->maxLength(255),
            Forms\Components\Textarea::make('content')
                ->label(__('campaigns.snippets.content'))
                ->helperText(__('campaigns.snippets.help'))
                ->rows(4)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('campaigns.snippets.name'))
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('content')
                    ->label(__('campaigns.snippets.content'))
                    ->limit(60)
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('permutations')
                    ->label(__('campaigns.snippets.permutations'))
                    ->state(fn (SpintaxSnippet $record): string => number_format(
                        app(Spintax::class)->permutationCount($record->content)
                    )),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                // D-28: filter to rows the actor may delete.
                Tables\Actions\DeleteBulkAction::make()
                    ->using(fn (Collection $records) => $records
                        ->filter(fn (SpintaxSnippet $record): bool => auth('customer')->user()?->can('delete', $record) ?? false)
                        ->each(fn (SpintaxSnippet $record) => $record->delete())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSpintaxSnippets::route('/'),
        ];
    }
}
