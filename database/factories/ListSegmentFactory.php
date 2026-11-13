<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SegmentMatchType;
use App\Models\EmailList;
use App\Models\ListSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListSegment>
 */
class ListSegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'list_id' => EmailList::factory(),
            'customer_id' => fn (array $attributes): ?int => EmailList::withoutTenancy()
                ->whereKey($attributes['list_id'])->value('customer_id'),
            'name' => ucfirst(fake()->words(2, true)),
            'match_type' => SegmentMatchType::All,
        ];
    }
}
