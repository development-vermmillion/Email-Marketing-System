<?php

namespace Database\Factories;

use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmailTemplate> */
class EmailTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'default_subject' => fake()->sentence(4),
            'html_content' => '<html><body><p>Hello {{name}},</p><p><a href="https://example.com/offer">See the offer</a></p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p></body></html>',
        ];
    }
}
