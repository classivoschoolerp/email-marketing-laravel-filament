<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SurveyResource\Pages;

use App\Enums\ExportFormat;
use App\Enums\SurveyResponderStatus;
use App\Filament\Customer\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\SurveyResponder;
use App\Services\Lists\FieldValueService;
use App\Services\Surveys\SurveyResponderExporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Responders list with every answer (spec F-2.8-005).
 *
 * One column per question, materialised from the survey's own field list —
 * the answers are text-backed, so each cell is decoded through the shared
 * {@see FieldValueService} codec rather than printed raw (a multiselect
 * answer must read as "A, B", not as a JSON array).
 *
 * The CSV export rides the existing export engine: a queued job writes to
 * the private disk and the customer gets the usual signed download link.
 */
class SurveyResponders extends ManageRelatedRecords
{
    protected static string $resource = SurveyResource::class;

    protected static string $relationship = 'responders';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    public static function getNavigationLabel(): string
    {
        return __('surveys.responders.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('surveys.responders.page_title', ['name' => $this->getOwnerRecord()->name]);
    }

    public function getOwnerRecord(): Survey
    {
        /** @var Survey $record */
        $record = $this->getRecord();

        return $record;
    }

    public function form(Form $form): Form
    {
        // Responses are append-only (SurveyResponderPolicy): nothing to edit.
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        $survey = $this->getOwnerRecord();
        $fieldValues = app(FieldValueService::class);

        $columns = [
            Tables\Columns\TextColumn::make('email')
                ->label(__('surveys.responders.email'))
                ->placeholder(__('surveys.responders.anonymous'))
                ->searchable(),

            Tables\Columns\TextColumn::make('identity')
                ->label(__('surveys.responders.identity'))
                ->badge()
                ->state(fn (SurveyResponder $record): string => $record->isAnonymous()
                    ? __('surveys.responders.anonymous')
                    : __('surveys.responders.identified'))
                ->color(fn (SurveyResponder $record): string => $record->isAnonymous() ? 'gray' : 'success'),

            Tables\Columns\TextColumn::make('status')
                ->label(__('surveys.responders.status'))
                ->badge()
                ->formatStateUsing(fn (SurveyResponderStatus $state): string => $state->label())
                ->color(fn (SurveyResponderStatus $state): string => $state === SurveyResponderStatus::Complete ? 'success' : 'warning'),

            Tables\Columns\TextColumn::make('completed_at')
                ->label(__('surveys.responders.completed_at'))
                ->dateTime()
                ->placeholder('—')
                ->sortable(),
        ];

        // One column per question — the answers themselves (F-2.8-005).
        foreach ($survey->orderedFields() as $field) {
            $columns[] = Tables\Columns\TextColumn::make('answer_'.$field->id)
                ->label($field->label)
                ->wrap()
                ->toggleable()
                ->state(function (SurveyResponder $record) use ($field, $fieldValues): string {
                    $raw = $record->answers->firstWhere('survey_field_id', $field->id)?->value;

                    return self::display($fieldValues->fromStorage($field, $raw === null ? null : (string) $raw));
                });
        }

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('answers'))
            ->columns($columns)
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('surveys.responders.status'))
                    ->options(SurveyResponderStatus::options()),
                Tables\Filters\TernaryFilter::make('identified')
                    ->label(__('surveys.responders.identity'))
                    ->placeholder(__('surveys.responders.all'))
                    ->trueLabel(__('surveys.responders.identified'))
                    ->falseLabel(__('surveys.responders.anonymous'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('subscriber_id'),
                        false: fn ($query) => $query->whereNull('subscriber_id'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('export')
                    ->label(__('surveys.responders.export'))
                    ->icon('heroicon-m-arrow-down-tray')
                    ->form([
                        Forms\Components\Select::make('format')
                            ->label(__('surveys.export.format'))
                            ->options([
                                ExportFormat::Csv->value => 'CSV',
                                ExportFormat::Xlsx->value => 'XLSX',
                            ])
                            ->default(ExportFormat::Csv->value)
                            ->required(),
                    ])
                    ->action(function (array $data) use ($survey): void {
                        app(SurveyResponderExporter::class)->queue($survey, $data['format'] ?? null);

                        Notification::make()
                            ->title(__('surveys.export.queued'))
                            ->body(__('surveys.export.queued_body'))
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('stats')
                ->label(__('surveys.actions.stats'))
                ->icon('heroicon-m-chart-pie')
                ->color('gray')
                ->url(fn (): string => SurveyResource::getUrl('stats', ['record' => $this->getOwnerRecord()])),
        ];
    }

    /** Typed answer → one display string. */
    public static function display(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_array($value)) {
            return $value === [] ? '—' : implode(', ', array_map(fn (mixed $v): string => self::display($v), $value));
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        if (is_bool($value)) {
            return $value ? __('surveys.stats.yes') : __('surveys.stats.no');
        }

        return (string) $value;
    }
}
