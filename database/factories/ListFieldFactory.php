<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ListFieldType;
use App\Models\EmailList;
use App\Models\ListField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListField>
 */
class ListFieldFactory extends Factory
{
    public function definition(): array
    {
        return [
            'list_id' => EmailList::factory(),
            'customer_id' => fn (array $attributes): ?int => EmailList::withoutTenancy()
                ->whereKey($attributes['list_id'])->value('customer_id'),
            'type' => ListFieldType::Text,
            'label' => ucfirst(fake()->word()),
            'tag' => strtoupper(fake()->unique()->lexify('FIELD????')),
            'required' => false,
            'sort_order' => 0,
        ];
    }

    public function type(ListFieldType $type): static
    {
        return $this->state(['type' => $type]);
    }
}
