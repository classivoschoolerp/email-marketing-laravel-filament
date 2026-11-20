<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListFieldType;
use App\Models\Survey;
use App\Models\SurveyField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyField>
 */
class SurveyFieldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'customer_id' => fn (array $attributes): ?int => Survey::withoutTenancy()
                ->whereKey($attributes['survey_id'])->value('customer_id'),
            'type' => ListFieldType::Text,
            'label' => ucfirst(fake()->words(3, true)).'?',
            'tag' => strtoupper(fake()->unique()->lexify('Q????')),
            'required' => false,
            'sort_order' => 0,
        ];
    }

    public function type(ListFieldType $type): static
    {
        return $this->state(['type' => $type]);
    }
}
