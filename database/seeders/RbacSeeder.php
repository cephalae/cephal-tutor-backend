<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cached roles/permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /**
         * Define your permission catalog here.
         * Keep names stable and consistent; they become your authorization contract.
         */
        $adminPermissions = [
            'providers.view',
            'providers.create',
            'providers.update',
            'providers.delete',

            'provider_users.view',
            'provider_users.create',
            'provider_users.update',
            'provider_users.delete',

            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',

            'permissions.view',
            'permissions.create',
            'permissions.update',
            'permissions.delete',

            'users.roles.assign', // endpoints below
        ];

        $providerPermissions = [
            // Provider-side (students/provider admins)
            'courses.view',
            'courses.enroll',

            'lessons.view',
            'assessments.take',

            'coding_cases.view',
            'coding_cases.submit',

            // Provider admin capabilities (within provider)
            'provider_users.view',
            'provider_users.create',
            'provider_users.update',
            'provider_users.delete',
        ];

        // Seed permissions per guard
        $this->seedPermissions($adminPermissions, 'admin_api');
        $this->seedPermissions($providerPermissions, 'provider_api');

        // Seed roles (basic defaults)
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'admin_api']);
        $admin      = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'admin_api']);

        $providerAdmin = Role::firstOrCreate(['name' => 'provider_admin', 'guard_name' => 'provider_api']);
        $student       = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'provider_api']);

        // Attach permissions to roles
        $superAdmin->syncPermissions(Permission::where('guard_name', 'admin_api')->get()); // all admin perms
        $admin->syncPermissions([
            'providers.view',
            'provider_users.view',
            'roles.view',
            'permissions.view',
        ]);

        $providerAdmin->syncPermissions(Permission::where('guard_name', 'provider_api')->get()); // all provider perms
        $student->syncPermissions([
            'courses.view',
            'courses.enroll',
            'lessons.view',
            'assessments.take',
            'coding_cases.view',
            'coding_cases.submit',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedPermissions(array $names, string $guard): void
    {
        foreach ($names as $name) {
            Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }
    }
}
