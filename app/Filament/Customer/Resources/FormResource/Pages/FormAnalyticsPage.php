<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\FormResource\Pages;

use App\Filament\Customer\Resources\FormResource;
use App\Models\Form as FormModel;
use App\Models\FormSubmission;
use App\Services\Campaigns\AbTest\SignificanceTest;
use App\Services\Forms\FormAbTest;
use App\Services\Forms\FormAnalytics;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Form analytics (spec F-2.7-012): views, submits and conversion rate for
 * the form as a whole, the same three broken down by source, a 30-day
 * series and the most recent submissions.
 */
class FormAnalyticsPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FormResource::class;

    protected static string $view = 'filament.customer.resources.form-resource.pages.form-analytics';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(static::getResource()::canView($this->getRecord()), 403);
    }

    public function getRecord(): FormModel
    {
        /** @var FormModel $record */
        $record = $this->record;

        return $record;
    }

    public function getTitle(): string
    {
        return __('forms.analytics.title').' — '.$this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('embed')
                ->label(__('forms.actions.embed'))
                ->icon('heroicon-m-code-bracket')
                ->color('gray')
                ->url(fn (): string => FormResource::getUrl('embed', ['record' => $this->getRecord()])),
        ];
    }

    /** @return array{views:int, submissions:int, conversion_rate:float} */
    public function summary(): array
    {
        return app(FormAnalytics::class)->summary($this->getRecord());
    }

    /** @return list<array{source:string, views:int, submissions:int, conversion_rate:float}> */
    public function bySource(): array
    {
        return app(FormAnalytics::class)->bySource($this->getRecord());
    }

    /** @return list<array{date:string, views:int, submissions:int}> */
    public function daily(): array
    {
        return app(FormAnalytics::class)->daily($this->getRecord(), 30);
    }

    /**
     * The A/B read-out (F-2.7-013): per-arm conversion plus the confidence
     * each challenger has against the baseline, from the ONE two-proportion
     * z-test the product owns ({@see \App\Services\Campaigns\AbTest\SignificanceTest}).
     *
     * @return array{enabled:bool, baseline_id:int|null, winner_id:int|null, level:float, arms:list<array<string, mixed>>}
     */
    public function abReport(): array
    {
        return app(FormAbTest::class)->report($this->getRecord());
    }

    /** The minimum per-arm traffic below which no confidence is claimed. */
    public function abMinTrials(): int
    {
        return SignificanceTest::MIN_TRIALS;
    }

    /** @return Collection<int, FormSubmission> */
    public function recentSubmissions(): Collection
    {
        return FormSubmission::query()
            ->where('form_id', $this->getRecord()->id)
            ->latest('id')
            ->limit(25)
            ->get();
    }
}
