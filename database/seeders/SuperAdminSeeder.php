<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@eye.test'],
            [
                'name' => 'Super Admin',
                'email' => 'admin@eye.test',
                'password' => Hash::make('Password1!'),
                'email_verified_at' => now(),
                'role' => 'superadmin',
                'status' => 'active',
                'api_key' => Str::random(64),
                'locale' => 'en',
                'timezone' => 'UTC',
                'appearance' => 'system',
                'totp_enabled' => false,
            ]
        );

        $this->command->info('Super admin created: admin@eye.test / Password1!');
    }
}
