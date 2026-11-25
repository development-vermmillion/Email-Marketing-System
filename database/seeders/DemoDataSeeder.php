<?php

namespace Database\Seeders;

use App\Models\Audience;
use App\Models\AudienceGroup;
use Illuminate\Database\Seeder;

/** Optional: php artisan db:seed --class=DemoDataSeeder  (local/staging only) */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $groups = AudienceGroup::factory()->count(3)->create();

        Audience::factory()->count(200)->create()->each(
            fn (Audience $a) => $a->groups()->sync($groups->random(random_int(1, 2))->pluck('id'))
        );
    }
}
