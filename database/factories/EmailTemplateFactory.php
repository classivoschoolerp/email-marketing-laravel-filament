<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TemplateBuilder;
use App\Models\Customer;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->unique()->words(3, true),
            'builder' => TemplateBuilder::Html,
            'content_html' => '<html><body><p>Hello {SUBSCRIBER_NAME}</p><p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p></body></html>',
        ];
    }

    public function gallery(): static
    {
        return $this->state([
            'customer_id' => null,
            'is_gallery' => true,
        ]);
    }

    public function system(): static
    {
        return $this->state(['customer_id' => null]);
    }

    public function grapesjs(): static
    {
        return $this->state([
            'builder' => TemplateBuilder::GrapesJs,
            'content_json' => json_encode(['pages' => []]),
        ]);
    }
}
