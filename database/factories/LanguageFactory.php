<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        return [
            'code' => $this->faker->unique()->languageCode(),
            'name' => $this->faker->word(),
            'rtl' => false,
            'enabled' => true,
            'is_default' => false,
        ];
    }
}
