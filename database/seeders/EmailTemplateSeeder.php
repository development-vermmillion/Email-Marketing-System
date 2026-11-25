<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::firstOrCreate(
            ['name' => 'Responsive announcement'],
            [
                'default_subject' => 'Something new from us',
                'html_content' => file_get_contents(__DIR__.'/stubs/welcome-template.html'),
            ]
        );
    }
}
