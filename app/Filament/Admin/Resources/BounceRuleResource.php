<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\BounceType;
use App\Filament\Admin\Resources\BounceRuleResource\Pages;
use App\Models\BounceRule;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Bounce classification rules (spec §2.9.4 F-2.9.4-006, WP-15): the seeded
 * default hard/soft/internal regex set plus operator-defined custom rules,
 * evaluated in sort_order by BounceClassifier. Patterns are validated as
 * real regexes on save.
 */
class BounceRuleResource extends Resource
{
    protected static ?string $model = BounceRule::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';
    protected static ?string $navigationGroup = 'Deliverability';

    protected static ?string $slug = 'bounce-rules';

    protected static ?int $navigationSort = 74;

    public static function getModelLabel(): string
    {
        return __('infra.rules.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('infra.rules.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('infra.rules.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('blacklist.admin_nav_group');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('infra.rules.section'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('infra.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('type')
                        ->label(__('infra.rules.type'))
                        ->options(BounceType::options())
                        ->required(),
                    Forms\Components\TextInput::make('pattern')
                        ->label(__('infra.rules.pattern'))
                        ->helperText(__('infra.rules.pattern_help'))
                        ->required()
                        ->maxLength(1000)
                        ->columnSpanFull()
                        ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                            if (@preg_match('#'.str_replace('#', '\#', (string) $value).'#im', '') === false) {
                                $fail(__('infra.rules.pattern_invalid'));
                            }
                        }),
                    Forms\Components\Toggle::make('enabled')
                        ->label(__('infra.rules.enabled'))
                        ->default(true),
                    Forms\Components\TextInput::make('sort_order')
                        ->label(__('infra.rules.sort_order'))
                        ->helperText(__('infra.rules.sort_order_help'))
                        ->numeric()->integer()->minValue(0)->default(100)->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('infra.rules.sort_order'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label(__('infra.fields.name'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('infra.rules.type'))
                    ->badge()
                    ->formatStateUsing(fn (BounceType $state): string => $state->label())
                    ->color(fn (BounceType $state): string => $state->color()),
                Tables\Columns\TextColumn::make('pattern')
                    ->label(__('infra.rules.pattern'))
                    ->limit(60)
                    ->wrap(),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->label(__('infra.rules.enabled')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('infra.rules.type'))
                    ->options(BounceType::options()),
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
            'index' => Pages\ListBounceRules::route('/'),
            'create' => Pages\CreateBounceRule::route('/create'),
            'edit' => Pages\EditBounceRule::route('/{record}/edit'),
        ];
    }
}
