<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Models\EmailList;
use App\Models\Form;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'list_id' => EmailList::factory(),
            'customer_id' => fn (array $attributes): ?int => EmailList::withoutTenancy()
                ->whereKey($attributes['list_id'])->value('customer_id'),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'type' => FormType::Embedded,
            'status' => FormStatus::Active,
            'heading' => 'Join the list',
            'captcha_enabled' => true,
        ];
    }

    public function draft(): static
    {
        return $this->state(['status' => FormStatus::Draft]);
    }

    public function type(FormType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function withConsent(?string $text = null): static
    {
        return $this->state([
            'consent_enabled' => true,
            'consent_required' => true,
            'consent_text' => $text ?? 'I agree to the privacy policy.',
        ]);
    }
}
