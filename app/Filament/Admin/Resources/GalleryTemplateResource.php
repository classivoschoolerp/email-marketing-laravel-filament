<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\GalleryTemplateResource\Pages;
use App\Models\CustomerGroup;
use App\Models\EmailTemplate;
use App\Models\Scopes\CustomerScope;
use App\Models\TemplateCategory;
use App\Services\Templates\Builders\BuilderRegistry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin system template gallery (spec §3.14): CRUD over system templates
 * (customer_id NULL), publish to the customer gallery with optional
 * customer-group restriction, featured flag + sort order, bulk pack import.
 * Guarded by EmailTemplatePolicy (templates.manage permission).
 */
class GalleryTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Content & Marketing';

    protected static ?string $slug = 'gallery-templates';

    protected static ?int $navigationSort = 60;

    public static function getModelLabel(): string
    {
        return __('templates.gallery.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('templates.gallery.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('templates.gallery.navigation_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('templates.resource.navigation_group');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScope(CustomerScope::class)
            ->whereNull('customer_id');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('templates.form.details'))
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('templates.fields.name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('category_id')
                        ->label(__('templates.fields.category'))
                        ->options(fn (): array => TemplateCategory::withoutTenancy()
                            ->whereNull('customer_id')
                            ->orderBy('sort_order')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->nullable(),
                    Forms\Components\Select::make('builder')
                        ->label(__('templates.fields.builder'))
                        ->options(fn (): array => app(BuilderRegistry::class)->options())
                        ->default('html')
                        ->in(array_keys(app(BuilderRegistry::class)->options()))
                        ->required(),
                    Forms\Components\FileUpload::make('thumbnail_path')
                        ->label(__('templates.fields.thumbnail'))
                        ->helperText(__('templates.help.thumbnail_upload'))
                        ->disk('public')
                        ->directory('gallery/thumbnails')
                        ->image()
                        ->maxSize((int) config('emp-templates.assets.max_kb', 5120))
                        ->nullable(),
                    Forms\Components\Toggle::make('is_featured')
                        ->label(__('templates.fields.is_featured')),
                    Forms\Components\TextInput::make('sort_order')
                        ->label(__('templates.fields.sort_order'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                ]),
            Forms\Components\Section::make(__('templates.form.content'))
                ->schema([
                    Forms\Components\Textarea::make('content_html')
                        ->label(__('templates.fields.content'))
                        ->rows(20)
                        ->extraAttributes(['class' => 'font-mono text-xs'])
                        ->nullable(),
                ]),
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
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('templates.fields.category'))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_gallery')
                    ->label(__('templates.fields.published'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('gallery_customer_groups')
                    ->label(__('templates.fields.gallery_groups'))
                    ->state(function (EmailTemplate $record): string {
                        $groups = $record->gallery_customer_groups;

                        if ($groups === null) {
                            return __('templates.gallery.all_groups');
                        }

                        return CustomerGroup::query()
                            ->whereIn('id', $groups)
                            ->pluck('name')
                            ->implode(', ');
                    }),
                Tables\Columns\ToggleColumn::make('is_featured')
                    ->label(__('templates.fields.is_featured')),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label(__('templates.fields.sort_order'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('templates.fields.updated_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_gallery')
                    ->label(__('templates.filters.published')),
                Tables\Filters\SelectFilter::make('category_id')
                    ->label(__('templates.filters.category'))
                    ->options(fn (): array => TemplateCategory::withoutTenancy()
                        ->whereNull('customer_id')
                        ->orderBy('sort_order')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('publish')
                    ->label(__('templates.actions.publish'))
                    ->icon('heroicon-m-eye')
                    ->visible(fn (EmailTemplate $record): bool => ! $record->is_gallery)
                    ->form([
                        Forms\Components\Select::make('groups')
                            ->label(__('templates.fields.gallery_groups'))
                            ->helperText(__('templates.help.gallery_groups'))
                            ->multiple()
                            ->options(fn (): array => CustomerGroup::query()->pluck('name', 'id')->all()),
                    ])
                    ->action(function (EmailTemplate $record, array $data): void {
                        $groups = array_map('intval', $data['groups'] ?? []);

                        $record->forceFill([
                            'is_gallery' => true,
                            'gallery_customer_groups' => $groups === [] ? null : array_values($groups),
                        ])->save();
                    }),

                Tables\Actions\Action::make('unpublish')
                    ->label(__('templates.actions.unpublish'))
                    ->icon('heroicon-m-eye-slash')
                    ->visible(fn (EmailTemplate $record): bool => $record->is_gallery)
                    ->action(fn (EmailTemplate $record) => $record->forceFill([
                        'is_gallery' => false,
                    ])->save()),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGalleryTemplates::route('/'),
            'create' => Pages\CreateGalleryTemplate::route('/create'),
            'edit' => Pages\EditGalleryTemplate::route('/{record}/edit'),
        ];
    }
}
