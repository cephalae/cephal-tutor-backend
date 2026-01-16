<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class MainAdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Main Admin',
                'password' => Hash::make('password'),
                'type' => 'admin',
                'provider_id' => null,
            ]
        );

        $role = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'admin_api']
        );

        $admin->syncRoles([$role]);
    }
}
