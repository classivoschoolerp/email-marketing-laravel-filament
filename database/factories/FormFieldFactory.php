<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListFieldType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormField>
 */
class FormFieldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'customer_id' => fn (array $attributes): ?int => Form::withoutTenancy()
                ->whereKey($attributes['form_id'])->value('customer_id'),
            'type' => ListFieldType::Text,
            'label' => ucfirst(fake()->word()),
            'tag' => strtoupper(fake()->unique()->lexify('FF????')),
            'required' => false,
            'sort_order' => 0,
        ];
    }

    public function type(ListFieldType $type): static
    {
        return $this->state(['type' => $type]);
    }

    /** Place the field on a given step of a multi-step form (F-2.7-004). */
    public function step(int $step): static
    {
        return $this->state(['step' => $step]);
    }
}
