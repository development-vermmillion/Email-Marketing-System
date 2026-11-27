<?php

namespace Database\Factories;

use App\Models\Audience;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Audience> */
class AudienceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+91 9#########'),
            'company' => fake()->company(),
            'status' => 'active',
            'subscription_status' => 'subscribed',
            'source' => 'manual',
            'consent_at' => now(),
        ];
    }

    public function unsubscribed(): static
    {
        return $this->state(fn () => ['subscription_status' => 'unsubscribed', 'unsubscribed_at' => now()]);
    }

    public function validationStatus(string $status, ?string $reason = null): static
    {
        return $this->state(fn () => ['email_validation_status' => $status, 'email_validation_reason' => $reason, 'email_validated_at' => now()]);
    }
}
