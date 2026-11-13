<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\TemplateBuilder;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EmailList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'email_list_id' => fn (array $attributes) => EmailList::factory()->state([
                'customer_id' => $attributes['customer_id'],
            ]),
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'type' => CampaignType::Regular,
            'status' => CampaignStatus::Draft,
            'from_name' => fake()->name(),
            'from_email' => fake()->safeEmail(),
            'subject' => fake()->sentence(4),
            'builder' => TemplateBuilder::Html,
            'content_html' => '<html><body><p>Hello {SUBSCRIBER_NAME}</p>'
                .'<p><a href="{UNSUBSCRIBE_URL}">Unsubscribe</a></p></body></html>',
            'track_opens' => true,
            'track_clicks' => true,
        ];
    }

    public function scheduled(): static
    {
        return $this->state([
            'status' => CampaignStatus::Scheduled,
            'send_at' => now()->addDay(),
        ]);
    }

    public function sending(): static
    {
        return $this->state([
            'status' => CampaignStatus::Sending,
            'send_started_at' => now(),
        ]);
    }

    public function sent(): static
    {
        return $this->state([
            'status' => CampaignStatus::Sent,
            'send_started_at' => now()->subHour(),
            'send_finished_at' => now(),
        ]);
    }

    public function autoresponder(): static
    {
        return $this->state([
            'type' => CampaignType::Autoresponder,
            'ar_trigger' => 'after-subscribe',
            'ar_delay_value' => 1,
            'ar_delay_unit' => 'days',
        ]);
    }

    public function recurring(): static
    {
        return $this->state([
            'type' => CampaignType::Recurring,
            'rec_interval' => 'daily',
            'rec_starts_at' => now()->addDay(),
        ]);
    }
}
