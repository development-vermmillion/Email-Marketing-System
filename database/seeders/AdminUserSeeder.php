<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'admin@example.com');
        $password = (string) env('ADMIN_PASSWORD', '');
        $generated = false;

        if ($password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        $user = User::firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        $user->name = $user->name ?: (string) env('ADMIN_NAME', 'Administrator');
        $user->is_admin = true;
        $user->is_active = true;

        // Never overwrite the password of an existing admin when re-seeding.
        if ($isNew) {
            $user->password = $password;
        }

        $user->save();

        if ($isNew && $generated && $this->command) {
            $this->command->warn("Admin created: {$email} / {$password}  (shown once - change it after first login)");
        }
    }
}
