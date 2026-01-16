<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MainAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Force Spatie to use the admin guard in this seeding run
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Config::set('auth.defaults.guard', 'admin_api');
        Config::set('permission.defaults.guard', 'admin_api');

        // Create or update main admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Main Admin',
                'username' => 'mainadmin',
                'mobile' => '+910000000000',
                'password' => Hash::make('password'),
                'type' => 'admin',
                'provider_id' => null,
            ]
        );

        // Ensure role is created on correct guard
        $role = Role::firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'admin_api',
        ]);

        // Assign role (explicit guard)
        $admin->syncRoles([$role]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
