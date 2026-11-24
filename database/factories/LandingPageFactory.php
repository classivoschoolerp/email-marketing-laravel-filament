<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LandingPageStatus;
use App\Models\Customer;
use App\Models\LandingPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LandingPage>
 */
class LandingPageFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->words(2, true));

        return [
            'customer_id' => Customer::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => LandingPageStatus::Draft,
            'content_html' => '<h1>'.$name.'</h1>',
            'after_submit' => LandingPage::AFTER_MESSAGE,
            'robots' => 'index,follow',
        ];
    }

    public function published(): static
    {
        return $this->state([
            'status' => LandingPageStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function scheduled(?\DateTimeInterface $at = null): static
    {
        return $this->state([
            'status' => LandingPageStatus::Scheduled,
            'scheduled_at' => $at ?? now()->addDay(),
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(['status' => LandingPageStatus::Unpublished]);
    }
}
