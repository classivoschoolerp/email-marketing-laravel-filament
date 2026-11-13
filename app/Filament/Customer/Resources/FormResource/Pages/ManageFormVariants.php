<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\FormResource\Pages;

use App\Filament\Customer\Resources\FormResource;
use App\Models\Customer;
use App\Models\Form as FormModel;
use App\Models\FormField;
use App\Services\Forms\FormStyles;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A/B arms of a form (spec F-2.7-013).
 *
 * Every override is "empty means inherit", so the first arm a customer adds is
 * a control that reproduces the form exactly — which is what makes starting a
 * test one click rather than a re-authoring exercise.
 *
 * `field_ids` is the interesting control: it picks AND orders the inputs this
 * arm shows, which is how "does asking for a phone number cost us sign-ups?"
 * becomes an experiment instead of an opinion.
 */
class ManageFormVariants extends ManageRelatedRecords
{
    protected static string $resource = FormResource::class;

    protected static string $relationship = 'variants';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    public static function getNavigationLabel(): string
    {
        return __('forms.ab.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('forms.ab.page_title', ['name' => $this->getOwnerRecord()->name]);
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
            Forms\Components\TextInput::make('name')
                ->label(__('forms.ab.name'))
                ->required()
                ->maxLength(191),

            Forms\Components\TextInput::make('weight')
                ->label(__('forms.ab.weight'))
                ->helperText(__('forms.ab.weight_help'))
                ->numeric()
                ->minValue(0)
                ->maxValue(1000)
                ->default(50)
                ->required(),

            Forms\Components\TextInput::make('heading')
                ->label(__('forms.ab.heading'))
                ->placeholder(__('forms.ab.inherits'))
                ->maxLength(191),

            Forms\Components\Textarea::make('description')
                ->label(__('forms.ab.description'))
                ->placeholder(__('forms.ab.inherits'))
                ->rows(2),

            Forms\Components\TextInput::make('submit_label')
                ->label(__('forms.ab.submit_label'))
                ->placeholder(__('forms.ab.inherits'))
                ->maxLength(120),

            Forms\Components\Select::make('field_ids')
                ->label(__('forms.ab.field_ids'))
                ->helperText(__('forms.ab.field_ids_help'))
                ->multiple()
                ->options(fn (): array => $this->fieldOptions()),

            Forms\Components\Fieldset::make(__('forms.ab.styles'))->schema([
                Forms\Components\ColorPicker::make('styles.background_color')
                    ->label(__('forms.fields.background_color')),
                Forms\Components\ColorPicker::make('styles.button_color')
                    ->label(__('forms.fields.button_color')),
                Forms\Components\Select::make('styles.font')
                    ->label(__('forms.fields.font'))
                    ->options(FormStyles::fontOptions())
                    ->placeholder(__('forms.ab.inherits')),
            ])->columns(3),

            Forms\Components\Toggle::make('is_control')
                ->label(__('forms.ab.is_control'))
                ->helperText(__('forms.ab.is_control_help')),

            Forms\Components\Toggle::make('is_winner')
                ->label(__('forms.ab.is_winner'))
                ->helperText(__('forms.ab.is_winner_help')),

            Forms\Components\Toggle::make('enabled')
                ->label(__('forms.ab.enabled_arm'))
                ->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('forms.ab.name'))->searchable(),
                Tables\Columns\TextColumn::make('weight')->label(__('forms.ab.weight'))->numeric(),
                Tables\Columns\TextColumn::make('heading')
                    ->label(__('forms.ab.heading'))
                    ->placeholder(__('forms.ab.inherits'))
                    ->limit(40),
                Tables\Columns\IconColumn::make('is_control')->label(__('forms.ab.is_control'))->boolean(),
                Tables\Columns\IconColumn::make('is_winner')->label(__('forms.ab.is_winner'))->boolean(),
                Tables\Columns\IconColumn::make('enabled')->label(__('forms.ab.enabled_arm'))->boolean(),
            ])
            ->defaultSort('id')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label(__('forms.ab.add'))
                    ->mutateFormDataUsing(fn (array $data): array => $data + $this->tenantData()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading(__('forms.ab.empty'));
    }

    /** @return array<int, string> */
    private function fieldOptions(): array
    {
        return FormField::query()
            ->where('form_id', $this->getOwnerRecord()->id)
            ->orderBy('step')
            ->orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (FormField $field): array => [
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

}
