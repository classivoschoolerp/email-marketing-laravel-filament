<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\FormResource\Pages;

use App\Enums\ListFieldType;
use App\Filament\Customer\Resources\FormResource;
use App\Models\Customer;
use App\Models\Form as FormModel;
use App\Models\FormField;
use App\Models\ListField;
use App\Services\Forms\FormSteps;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The form builder (spec F-2.7-003): every §2.2.9 custom field type, added,
 * configured, drag-and-drop reordered and deleted on one page — the same
 * interaction model WP-06/07 shipped for list custom fields (F-2.2.9-007),
 * reusing the same type library rather than a second one.
 *
 * "Map to list field" is the important control: a mapped input lands on the
 * subscriber (and, for consent types, in the WP-22 audit trail); an unmapped
 * one is captured on the submission row only.
 */
class ManageFormFields extends ManageRelatedRecords
{
    protected static string $resource = FormResource::class;

    protected static string $relationship = 'fields';

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    public static function getNavigationLabel(): string
    {
        return __('forms.builder.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('forms.builder.page_title', ['name' => $this->getOwnerRecord()->name]);
    }

    public function getOwnerRecord(): FormModel
    {
        /** @var FormModel $record */
        $record = $this->getRecord();

        return $record;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label(__('forms.builder.type'))
                ->options(ListFieldType::options())
                ->default(ListFieldType::Text->value)
                ->required()
                ->live(),

            Forms\Components\TextInput::make('label')
                ->label(__('forms.builder.label'))
                ->required()
                ->maxLength(191),

            Forms\Components\TextInput::make('tag')
                ->label(__('forms.builder.tag'))
                ->helperText(__('forms.builder.tag_help'))
                ->required()
                ->maxLength(64)
                ->regex('/^[A-Za-z][A-Za-z0-9_]*$/')
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state)))
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (strtoupper(trim((string) $value)) === 'EMAIL') {
                        // The email input is built in; a field cannot shadow it.
                        $fail(__('forms.builder.tag_reserved', ['tag' => 'EMAIL']));
                    }
                })
                ->rule(fn (?FormField $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $exists = FormField::query()
                        ->where('form_id', $this->getOwnerRecord()->id)
                        ->where('tag', strtoupper(trim((string) $value)))
                        ->when($record !== null, fn ($query) => $query->whereKeyNot($record->id))
                        ->exists();

                    if ($exists) {
                        $fail(__('validation.unique', ['attribute' => __('forms.builder.tag')]));
                    }
                }),

            // F-2.7-004: which step this input appears on. Hidden entirely for
            // a single-step form so the common case gains no extra control.
            Forms\Components\Select::make('step')
                ->label(__('forms.builder.step'))
                ->helperText(__('forms.builder.step_help'))
                ->options(fn (): array => FormSteps::options($this->getOwnerRecord()))
                ->default(1)
                ->required()
                ->visible(fn (): bool => FormSteps::enabled($this->getOwnerRecord())),

            Forms\Components\Select::make('list_field_id')
                ->label(__('forms.builder.list_field'))
                ->options(fn (): array => $this->listFieldOptions())
                ->placeholder(__('forms.builder.list_field_none'))
                ->helperText(__('forms.builder.list_field_help')),

            Forms\Components\TextInput::make('placeholder')
                ->label(__('forms.builder.placeholder'))
                ->maxLength(191),

            Forms\Components\TextInput::make('default_value')
                ->label(__('forms.builder.default_value'))
                ->maxLength(1000),

            Forms\Components\TextInput::make('help_text')
                ->label(__('forms.builder.help_text'))
                ->maxLength(191),

            Forms\Components\Toggle::make('required')
                ->label(__('forms.builder.required')),

            Forms\Components\Repeater::make('options')
                ->label(__('forms.builder.options'))
                ->relationship('options')
                ->orderColumn('sort_order')
                ->reorderable()
                ->visible(fn (Forms\Get $get): bool => ListFieldType::tryFrom((string) $get('type'))?->hasOptions() ?? false)
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label(__('forms.builder.option_label'))
                        ->required()->maxLength(191),
                    Forms\Components\TextInput::make('value')
                        ->label(__('forms.builder.option_value'))
                        ->required()->maxLength(191),
                ])
                ->columns(2)
                ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => $data + $this->tenantData()),

            Forms\Components\Fieldset::make(__('forms.builder.validation'))->schema([
                Forms\Components\TextInput::make('validation.min_length')
                    ->label(__('lists.fields.min_length'))->numeric()->minValue(0)
                    ->visible(fn (Forms\Get $get): bool => self::isTextual($get)),
                Forms\Components\TextInput::make('validation.max_length')
                    ->label(__('lists.fields.max_length'))->numeric()->minValue(1)
                    ->visible(fn (Forms\Get $get): bool => self::isTextual($get)),
                Forms\Components\TextInput::make('validation.min_value')
                    ->label(__('lists.fields.min_value'))->numeric()
                    ->visible(fn (Forms\Get $get): bool => self::isNumericType($get)),
                Forms\Components\TextInput::make('validation.max_value')
                    ->label(__('lists.fields.max_value'))->numeric()
                    ->visible(fn (Forms\Get $get): bool => self::isNumericType($get)),
            ])->columns(2),
        ])->columns(1);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('step')
                    ->label(__('forms.builder.step'))
                    ->badge()
                    ->color('gray')
                    ->visible(fn (): bool => FormSteps::enabled($this->getOwnerRecord())),
                Tables\Columns\TextColumn::make('label')->label(__('forms.builder.label'))->searchable(),
                Tables\Columns\TextColumn::make('tag')->label(__('forms.builder.tag'))->badge()->color('gray'),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('forms.builder.type'))
                    ->formatStateUsing(fn (ListFieldType $state): string => $state->label()),
                Tables\Columns\IconColumn::make('required')->label(__('forms.builder.required'))->boolean(),
                Tables\Columns\TextColumn::make('listField.label')
                    ->label(__('forms.builder.list_field'))
                    ->placeholder(__('forms.builder.list_field_none')),
            ])
            // Drag & drop ordering — the builder's layout control (F-2.7-003).
            // Grouping by step (F-2.7-004) keeps the drag handle meaningful:
            // a reorder writes `sort_order`, which is read WITHIN a step.
            ->groups($this->stepGroups())
            ->defaultGroup(FormSteps::enabled($this->getOwnerRecord()) ? 'step' : null)
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('forms.builder.add'))
                    ->mutateFormDataUsing(fn (array $data): array => $data + $this->tenantData()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    /**
     * The "group by step" option, offered only when the form actually has
     * steps (F-2.7-004).
     *
     * @return array<int, Tables\Grouping\Group>
     */
    private function stepGroups(): array
    {
        if (! FormSteps::enabled($this->getOwnerRecord())) {
            return [];
        }

        return [
            Tables\Grouping\Group::make('step')
                ->label(__('forms.builder.step'))
                ->getTitleFromRecordUsing(fn (FormField $record): string => __('forms.steps.step_n', [
                    'number' => FormSteps::stepOf($record, FormSteps::count($this->getOwnerRecord())),
                ]))
                ->orderQueryUsing(fn ($query, string $direction) => $query->orderBy('step', $direction)),
        ];
    }

    /**
     * Custom fields of the form's own list, plus their type, so the mapping
     * choice is obvious.
     *
     * @return array<int, string>
     */
    private function listFieldOptions(): array
    {
        $listId = (int) $this->getOwnerRecord()->list_id;

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
