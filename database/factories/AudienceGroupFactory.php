<?php

namespace Database\Factories;

use App\Models\AudienceGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AudienceGroup> */
class AudienceGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement(['#5b4cf5', '#16a34a', '#f59e0b', '#0ea5e9', '#ec4899']),
        ];
    }
}
