<?php

namespace Database\Factories;

use App\Models\SmtpSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SmtpSetting> */
class SmtpSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Primary relay',
            'provider' => 'generic',
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'relay-user',
            'password' => 'relay-secret',
            'from_name' => 'Acme Marketing',
            'from_email' => 'news@example.com',
            'reply_to_email' => 'support@example.com',
            'is_active' => true,
            'is_default' => true,
            'rate_per_second' => 10,
            'rate_per_minute' => 500,
            'daily_limit' => null,
            'webhook_secret' => 'whsec_test_secret',
        ];
    }
}
