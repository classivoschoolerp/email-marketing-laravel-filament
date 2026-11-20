<?php

declare(strict_types=1);

namespace App\Filament\Customer\Resources\SurveyResource\Pages;

use App\Filament\Customer\Resources\SurveyResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create a survey (F-2.8-002). Quota-gated by SurveyPolicy::create
 * (`max_surveys` + `surveys.enabled`, §3.5).
 */
class CreateSurvey extends CreateRecord
{
    protected static string $resource = SurveyResource::class;

    protected function getRedirectUrl(): string
    {
        // Straight into the question builder — a survey with no questions
        // has nothing to publish.
        return SurveyResource::getUrl('questions', ['record' => $this->getRecord()]);
    }
}
