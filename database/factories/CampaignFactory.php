<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'subject' => fake()->sentence(5),
            'from_name' => 'Acme Marketing',
            'from_email' => 'news@example.com',
            'reply_to_email' => 'support@example.com',
            'email_template_id' => EmailTemplate::factory(),
            'status' => CampaignStatus::Draft,
            'group_ids' => [],
        ];
    }

    public function status(CampaignStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
