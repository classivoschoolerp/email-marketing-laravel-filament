<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SurveyResource\Pages;

use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\SurveyResource;
use App\Models\Customer;
use App\Models\ListField;
use App\Models\Survey;
use App\Models\SurveyField;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Survey questions (spec F-2.8-003): the same §2.2.9 type library as list
 * custom fields and form inputs, with drag & drop ordering — the order here
 * is also the order the §1.9 wizard walks and the order the export columns
 * appear in.
 *
 * "Map to list field" makes an answer a property of the subscriber, which is
 * what F-2.8-007 segments on; the segment action can also create the mapping
 * later, so leaving it empty is not a dead end.
 */
class ManageSurveyFields extends ManageRelatedRecords
{
    protected static string $resource = SurveyResource::class;

    protected static string $relationship = 'fields';

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    public static function getNavigationLabel(): string
    {
        return __('surveys.questions.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('surveys.questions.page_title', ['name' => $this->getOwnerRecord()->name]);
    }

    public function getOwnerRecord(): Survey
    {
        /** @var Survey $record */
        $record = $this->getRecord();

        return $record;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label(__('surveys.questions.type'))
                ->options(ListFieldType::options())
                ->default(ListFieldType::Text->value)
                ->required()
                ->live(),

            Forms\Components\TextInput::make('label')
                ->label(__('surveys.questions.label'))
                ->required()
                ->maxLength(191),

            Forms\Components\TextInput::make('tag')
                ->label(__('surveys.questions.tag'))
                ->helperText(__('surveys.questions.tag_help'))
                ->required()
                ->maxLength(64)
                ->regex('/^[A-Za-z][A-Za-z0-9_]*$/')
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state)))
                ->rule(fn (?SurveyField $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $exists = SurveyField::query()
                        ->where('survey_id', $this->getOwnerRecord()->id)
                        ->where('tag', strtoupper(trim((string) $value)))
                        ->when($record !== null, fn ($query) => $query->whereKeyNot($record->id))
                        ->exists();

                    if ($exists) {
                        $fail(__('validation.unique', ['attribute' => __('surveys.questions.tag')]));
                    }
                }),

            Forms\Components\Select::make('list_field_id')
                ->label(__('surveys.questions.list_field'))
                ->options(fn (): array => $this->listFieldOptions())
                ->placeholder(__('surveys.questions.list_field_none'))
                ->helperText(__('surveys.questions.list_field_help'))
                ->visible(fn (): bool => $this->getOwnerRecord()->list_id !== null),

            Forms\Components\TextInput::make('help_text')
                ->label(__('surveys.questions.help_text'))
                ->maxLength(191),

            Forms\Components\Toggle::make('required')
                ->label(__('surveys.questions.required')),

            Forms\Components\Repeater::make('options')
                ->label(__('surveys.questions.options'))
                ->relationship('options')
                ->orderColumn('sort_order')
                ->reorderable()
                ->visible(fn (Forms\Get $get): bool => ListFieldType::tryFrom((string) $get('type'))?->hasOptions() ?? false)
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label(__('surveys.questions.option_label'))
                        ->required()->maxLength(191),
                    Forms\Components\TextInput::make('value')
                        ->label(__('surveys.questions.option_value'))
                        ->required()->maxLength(191),
                ])
                ->columns(2)
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + $this->tenantData()),

            Forms\Components\Fieldset::make(__('surveys.questions.validation'))->schema([
                Forms\Components\TextInput::make('validation.min_value')
                    ->label(__('lists.fields.min_value'))->numeric()
                    ->visible(fn (Forms\Get $get): bool => self::isNumericType($get)),
                Forms\Components\TextInput::make('validation.max_value')
                    ->label(__('lists.fields.max_value'))->numeric()
                    ->visible(fn (Forms\Get $get): bool => self::isNumericType($get)),
                Forms\Components\TextInput::make('validation.max_length')
                    ->label(__('lists.fields.max_length'))->numeric()->minValue(1)
                    ->visible(fn (Forms\Get $get): bool => self::isTextual($get)),
            ])->columns(2),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('label')->label(__('surveys.questions.label'))->searchable(),
                Tables\Columns\TextColumn::make('tag')->label(__('surveys.questions.tag'))->badge()->color('gray'),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('surveys.questions.type'))
                    ->formatStateUsing(fn (ListFieldType $state): string => $state->label()),
                Tables\Columns\IconColumn::make('required')->label(__('surveys.questions.required'))->boolean(),
                Tables\Columns\TextColumn::make('listField.label')
                    ->label(__('surveys.questions.list_field'))
                    ->placeholder(__('surveys.questions.list_field_none')),
                Tables\Columns\TextColumn::make('answers_count')
                    ->label(__('surveys.questions.answers'))
                    ->counts('answers'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('surveys.questions.add'))
                    ->mutateFormDataUsing(fn (array $data): array => $data + $this->tenantData()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalDescription(fn (SurveyField $record): string => __('surveys.questions.delete_warning', [
                        'label' => $record->label,
                        'count' => $record->answers()->count(),
                    ])),
            ]);
    }

    /** @return array<int, string> */
    private function listFieldOptions(): array
    {
        $listId = (int) $this->getOwnerRecord()->list_id;

        if ($listId <= 0) {
            return [];
        }

        return ListField::query()
            ->where('list_id', $listId)
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (ListField $field): array => [
                (int) $field->id => $field->label.' ('.$field->tag.')',
            ])
            ->all();
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
}
