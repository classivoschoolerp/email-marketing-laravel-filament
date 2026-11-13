<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\EmailListResource\Pages;

use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\EmailListResource;
use App\Models\Customer;
use App\Models\EmailList;
use App\Models\ListField;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Custom fields manager for a list (spec §2.2.9): P1 field types, per-field
 * label/tag/default/help/required/visibility flags, option manager for choice
 * types, validation rule config, drag & drop reorder and delete with a
 * data-loss warning.
 */
class ManageListFields extends ManageRelatedRecords
{
    protected static string $resource = EmailListResource::class;

    protected static string $relationship = 'fields';

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    public static function getNavigationLabel(): string
    {
        return __('lists.fields.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lists.fields.page_title', ['name' => $this->getOwnerRecord()->name]);
    }

    public function getOwnerRecord(): EmailList
    {
        /** @var EmailList $record */
        $record = $this->getRecord();

        return $record;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label(__('lists.fields.type'))
                ->options(ListFieldType::options())
                ->default(ListFieldType::Text->value)
                ->required()
                ->live(),
            Forms\Components\TextInput::make('label')
                ->label(__('lists.fields.label'))
                ->required()
                ->maxLength(191),
            Forms\Components\TextInput::make('tag')
                ->label(__('lists.fields.tag'))
                ->helperText(__('lists.fields.tag_help'))
                ->required()
                ->maxLength(64)
                ->regex('/^[A-Za-z][A-Za-z0-9_]*$/')
                // Merge tags are canonically uppercase (F-2.2.9-002).
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state)))
                // Reserved tag protection: EMAIL etc. (F-2.2.9-006).
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (ListField::isReservedTag((string) $value)) {
                        $fail(__('lists.fields.tag_reserved', ['tag' => strtoupper(trim((string) $value))]));
                    }
                })
                // Unique per list, case-insensitively (tags are stored uppercase,
                // so compare against the canonical form, not the raw state).
                ->rule(fn (?ListField $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $exists = ListField::query()
                        ->where('list_id', $this->getOwnerRecord()->id)
                        ->where('tag', strtoupper(trim((string) $value)))
                        ->when($record !== null, fn ($query) => $query->whereKeyNot($record->id))
                        ->exists();

                    if ($exists) {
                        $fail(__('validation.unique', ['attribute' => __('lists.fields.tag')]));
                    }
                }),
            Forms\Components\TextInput::make('default_value')
                ->label(__('lists.fields.default_value'))
                ->maxLength(1000),
            Forms\Components\TextInput::make('help_text')
                ->label(__('lists.fields.help_text'))
                ->maxLength(191),
            Forms\Components\Fieldset::make(__('lists.fields.flags'))->schema([
                Forms\Components\Toggle::make('required')
                    ->label(__('lists.fields.required')),
                // Unique-value enforcement (F-2.2.9-005).
                Forms\Components\Toggle::make('is_unique')
                    ->label(__('lists.fields.is_unique'))
                    ->helperText(__('lists.fields.is_unique_help')),
                Forms\Components\Toggle::make('visible_on_form')
                    ->label(__('lists.fields.visible_on_form'))
                    ->default(true),
                Forms\Components\Toggle::make('visible_on_profile')
                    ->label(__('lists.fields.visible_on_profile'))
                    ->default(true),
                Forms\Components\Toggle::make('show_in_table')
                    ->label(__('lists.fields.show_in_table'))
                    ->helperText(__('lists.fields.show_in_table_help')),
            ])->columns(2),
            // Option manager for choice types (F-2.2.9-003): name + value, sortable.
            Forms\Components\Repeater::make('options')
                ->label(__('lists.fields.options'))
                ->relationship('options')
                ->orderColumn('sort_order')
                ->reorderable()
                ->visible(fn (Forms\Get $get): bool => ListFieldType::tryFrom((string) $get('type'))?->hasOptions() ?? false)
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label(__('lists.fields.option_label'))
                        ->required()
                        ->maxLength(191),
                    Forms\Components\TextInput::make('value')
                        ->label(__('lists.fields.option_value'))
                        ->required()
                        ->maxLength(191),
                ])
                ->columns(2)
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + $this->tenantData()),
            // Validation rules (F-2.2.9-004): min/max length, regex, numeric range, date range.
            Forms\Components\Fieldset::make(__('lists.fields.validation'))->schema([
                Forms\Components\TextInput::make('validation.min_length')
                    ->label(__('lists.fields.min_length'))
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (Forms\Get $get): bool => self::isTextual($get)),
                Forms\Components\TextInput::make('validation.max_length')
                    ->label(__('lists.fields.max_length'))
                    ->numeric()
                    ->minValue(1)
                    ->gte('validation.min_length')
                    ->visible(fn (Forms\Get $get): bool => self::isTextual($get)),
                Forms\Components\TextInput::make('validation.regex')
                    ->label(__('lists.fields.regex'))
                    ->helperText(__('lists.fields.regex_help'))
                    ->visible(fn (Forms\Get $get): bool => self::isTextual($get))
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value !== null && $value !== '' && @preg_match((string) $value, '') === false) {
                            $fail(__('lists.fields.regex_invalid'));
                        }
                    }),
                Forms\Components\TextInput::make('validation.min_value')
                    ->label(__('lists.fields.min_value'))
                    ->numeric()
                    ->visible(fn (Forms\Get $get): bool => self::isNumericType($get)),
                Forms\Components\TextInput::make('validation.max_value')
                    ->label(__('lists.fields.max_value'))
                    ->numeric()
                    ->gte('validation.min_value')
                    ->visible(fn (Forms\Get $get): bool => self::isNumericType($get)),
                Forms\Components\DatePicker::make('validation.min_date')
                    ->label(__('lists.fields.min_date'))
                    ->visible(fn (Forms\Get $get): bool => self::isDateType($get)),
                Forms\Components\DatePicker::make('validation.max_date')
                    ->label(__('lists.fields.max_date'))
                    ->after('validation.min_date')
                    ->visible(fn (Forms\Get $get): bool => self::isDateType($get)),
            ])->columns(2),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label(__('lists.fields.label'))
                    ->searchable(),
                Tables\Columns\TextColumn::make('tag')
                    ->label(__('lists.fields.tag'))
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('lists.fields.type'))
                    ->formatStateUsing(fn (ListFieldType $state): string => $state->label()),
                Tables\Columns\IconColumn::make('required')
                    ->label(__('lists.fields.required'))
                    ->boolean(),
                Tables\Columns\IconColumn::make('visible_on_form')
                    ->label(__('lists.fields.visible_on_form'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('show_in_table')
                    ->label(__('lists.fields.show_in_table'))
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('values_count')
                    ->label(__('lists.fields.values_count'))
                    ->counts('values'),
            ])
            // Drag & drop reorder (F-2.2.9-007).
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('lists.fields.create'))
                    ->mutateFormDataUsing(fn (array $data): array => $data + $this->tenantData()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Delete with data-loss warning (F-2.2.9-008).
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading(__('lists.fields.delete_heading'))
                    ->modalDescription(fn (ListField $record): string => __('lists.fields.delete_warning', [
                        'label' => $record->label,
                        'count' => $record->values()->count(),
                    ])),
            ]);
    }

    /** @return array<string, int> */
    private function tenantData(): array
    {
        $user = Filament::auth()->user();

        return [
            'customer_id' => $user instanceof Customer ? $user->id : (int) $this->getOwnerRecord()->customer_id,
        ];
    }

    private static function isTextual(Forms\Get $get): bool
    {
        $type = ListFieldType::tryFrom((string) $get('type'));

        return $type !== null
            && ! $type->isNumeric()
            && ! $type->isDate()
            && ! $type->isBoolean()
            && ! $type->hasOptions();
    }

    private static function isNumericType(Forms\Get $get): bool
    {
        return ListFieldType::tryFrom((string) $get('type'))?->isNumeric() ?? false;
    }

    private static function isDateType(Forms\Get $get): bool
    {
        return ListFieldType::tryFrom((string) $get('type'))?->isDate() ?? false;
    }
}
