<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\CampaignRecipient> */
class CampaignRecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'company' => fake()->company(),
            'status' => 'pending',
            'tracking_token' => Str::random(64),
        ];
    }
}
