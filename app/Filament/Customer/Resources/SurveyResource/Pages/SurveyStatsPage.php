<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SurveyResource\Pages;

use App\Enums\SegmentOperator;
use App\Filament\Customer\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\SurveyField;
use App\Services\Surveys\SurveyAnswerSegmentBuilder;
use App\Services\Surveys\SurveyStats;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Throwable;

/**
 * Per-question aggregate stats + charts (spec F-2.8-006) and the
 * "create a list segment from an answer" action (F-2.8-007).
 *
 * The segment action deliberately goes through
 * {@see SurveyAnswerSegmentBuilder}: it maps the question onto a list custom
 * field, backfills past identified answers and then builds an ordinary
 * dynamic segment — so the result behaves like every other segment in the
 * product (live membership, campaign targeting, preview counts).
 */
class SurveyStatsPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SurveyResource::class;

    protected static string $view = 'filament.customer.resources.survey-resource.pages.survey-stats';

    /** Categorical slots, assigned in bucket order and never cycled. */
    public const COLORS = [
        '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4',
        '#7c5cd6', '#0e9aa7', '#c2410c', '#4d7c0f', '#9333ea',
    ];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getRecord(): Survey
    {
        /** @var Survey $record */
        $record = $this->record;

        return $record;
    }

    public function getTitle(): string
    {
        return __('surveys.stats.title').' — '.$this->getRecord()->name;
    }

    /** @return array{responders:int, completed:int, questions:list<array<string, mixed>>} */
    public function stats(): array
    {
        return app(SurveyStats::class)->forSurvey($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('responders')
                ->label(__('surveys.actions.responders'))
                ->icon('heroicon-m-users')
                ->color('gray')
                ->url(fn (): string => SurveyResource::getUrl('responders', ['record' => $this->getRecord()])),

            // F-2.8-007
            Action::make('segment')
                ->label(__('surveys.segment.action'))
                ->icon('heroicon-m-funnel')
                ->visible(fn (): bool => $this->getRecord()->list_id !== null)
                ->form([
                    Forms\Components\Select::make('survey_field_id')
                        ->label(__('surveys.segment.question'))
                        ->options(fn (): array => $this->getRecord()->fields()->pluck('label', 'id')->all())
                        ->required(),
                    Forms\Components\Select::make('operator')
                        ->label(__('surveys.segment.operator'))
                        ->options(SegmentOperator::optionsFor(SegmentOperator::forField()))
                        ->default(SegmentOperator::Is->value)
                        ->required(),
                    Forms\Components\TextInput::make('value')
                        ->label(__('surveys.segment.value'))
                        ->maxLength(191),
                    Forms\Components\TextInput::make('name')
                        ->label(__('surveys.segment.name'))
                        ->maxLength(191),
                ])
                ->action(function (array $data): void {
                    /** @var SurveyField|null $field */
                    $field = SurveyField::query()
                        ->where('survey_id', $this->getRecord()->id)
                        ->find($data['survey_field_id'] ?? 0);

                    if ($field === null) {
                        Notification::make()->title(__('surveys.segment.failed'))->danger()->send();

                        return;
                    }

                    try {
                        $segment = app(SurveyAnswerSegmentBuilder::class)->build(
                            $this->getRecord(),
                            $field,
                            (string) $data['operator'],
                            $data['value'] ?? null,
                            $data['name'] ?? null,
                        );
                    } catch (Throwable) {
                        Notification::make()->title(__('surveys.segment.failed'))->danger()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('surveys.segment.created', ['name' => $segment->name]))
                        ->body(__('surveys.segment.created_body'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public function color(int $index): string
    {
        return self::COLORS[$index % count(self::COLORS)];
    }
}
