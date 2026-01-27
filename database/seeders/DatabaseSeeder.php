<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => 'smartera'],
            ['name' => 'Smartera', 'plan' => 'internal', 'is_active' => true]
        );

        User::firstOrCreate(
            ['email' => 'info@smartera.com'],
            [
                'name' => 'Smartera Owner',
                'password' => Hash::make('Password!12345'),
                'tenant_id' => $tenant->id,
                'role' => 'owner',
            ]
        );
    }
}
