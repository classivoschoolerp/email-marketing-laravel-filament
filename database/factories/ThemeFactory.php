<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Theme;
use App\Settings\SkinSettings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    public function definition(): array
    {
        $slug = 'pkg-'.Str::lower(Str::random(8));

        return [
            'slug' => $slug,
            'name' => ucfirst(fake()->word()).' Theme',
            'version' => '1.0.0',
            'author' => fake()->name(),
            'stylesheet' => 'theme.css',
            'areas' => SkinSettings::AREAS,
            'palette' => ['primary' => '#123456', 'scheme' => 'light'],
        ];
    }
}
