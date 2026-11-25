<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'company_name' => config('app.name'),
            // CAN-SPAM / GDPR style footers need a real postal address. Set it in System Settings.
            'sender_address' => '',
            'track_opens' => '1',
            'track_clicks' => '1',
            'public_unsubscribe_notice' => 'You have been unsubscribed and will no longer receive marketing email from us.',
        ];

        foreach ($defaults as $key => $value) {
            if (Setting::query()->where('key', $key)->doesntExist()) {
                Setting::put($key, $value);
            }
        }
    }
}
