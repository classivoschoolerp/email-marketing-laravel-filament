<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriberSource;
use App\Enums\SubscriberStatus;
use App\Models\EmailList;
use App\Models\Subscriber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscriber>
 */
class SubscriberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'list_id' => EmailList::factory(),
            'customer_id' => fn (array $attributes): ?int => EmailList::withoutTenancy()
                ->whereKey($attributes['list_id'])->value('customer_id'),
            'email' => fake()->unique()->safeEmail(),
            'status' => SubscriberStatus::Confirmed,
            'source' => SubscriberSource::Manual,
            'subscribed_at' => now(),
        ];
    }

    public function unconfirmed(): static
    {
        return $this->state(['status' => SubscriberStatus::Unconfirmed, 'confirmed_at' => null]);
    }

    public function unsubscribed(): static
    {
        return $this->state(['status' => SubscriberStatus::Unsubscribed, 'unsubscribed_at' => now()]);
    }

    public function bounced(string $type = 'hard'): static
    {
        return $this->state([
            'status' => SubscriberStatus::Bounced,
            'bounce_type' => $type,
            'last_bounce_at' => now(),
            'last_bounce_reason' => 'mailbox unavailable',
            'bounces_count' => 1,
        ]);
    }
}
