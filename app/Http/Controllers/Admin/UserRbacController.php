<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserRbacController extends Controller
{
    use ApiResponds;

    /**
     * GET /api/admin/users/{user}/rbac
     */
    public function show(User $user)
    {
        $guard = $this->resolveUserGuard($user);

        return $this->ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'mobile' => $user->mobile,
                'type' => $user->type,
                'provider_id' => $user->provider_id,
                'guard' => $guard,
            ],
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ], 'User RBAC');
    }

    /**
     * PUT /api/admin/users/{user}/roles
     * Replaces all roles.
     * Body: { "roles": ["provider_admin"] }
     */
    public function syncRoles(Request $request, User $user)
    {
        $guard = $this->resolveUserGuard($user);

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'max:100'],
        ]);

        $roles = Role::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $data['roles'])
            ->get();

        if ($roles->count() !== count($data['roles'])) {
            return $this->fail("One or more roles are invalid for guard: {$guard}", null, 422);
        }

        $user->syncRoles($roles);
        $this->forgetPermissionCache();

        return $this->ok([
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ], 'Roles synced');
    }

    /**
     * POST /api/admin/users/{user}/roles
     * Adds roles without removing existing.
     * Body: { "roles": ["student"] }
     */
    public function attachRoles(Request $request, User $user)
    {
        $guard = $this->resolveUserGuard($user);

        $data = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'max:100'],
        ]);

        $roles = Role::query()
            ->where('guard_name', $guard)
            ->whereIn('name', $data['roles'])
            ->get();

        if ($roles->count() !== count($data['roles'])) {
            return $this->fail("One or more roles are invalid for guard: {$guard}", null, 422);
        }

        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        $this->forgetPermissionCache();

        return $this->ok([
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ], 'Roles attached');
    }

    /**
     * DELETE /api/admin/users/{user}/roles/{role}
     * role is role name (recommended) OR role id (we’ll accept name here).
     */
    public function detachRole(Request $request, User $user, string $role)
    {
        $guard = $this->resolveUserGuard($user);

        $roleModel = Role::query()
            ->where('guard_name', $guard)
            ->where('name', $role)
            ->first();

        if (! $roleModel) {
            return $this->fail("Role not found for guard: {$guard}", null, 404);
        }

        $user->removeRole($roleModel);
        $this->forgetPermissionCache();

        return $this->ok([
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ], 'Role detached');
    }

    private function resolveUserGuard(User $user): string
    {
        // Use your model logic if present; otherwise infer.
        if (method_exists($user, 'getDefaultGuardName')) {
            return $user->getDefaultGuardName();
        }

        return $user->type === 'admin' ? 'admin_api' : 'provider_api';
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
