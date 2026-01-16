<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/admin/roles?guard_name=admin_api|provider_api
     */
    public function index(Request $request)
    {
        $guard = $this->resolveGuard($request);

        $query = Role::query()
            ->where('guard_name', $guard)
            ->with(['permissions:id,name,guard_name'])
            ->orderBy('name');

        $paginator = $this->paginate($query, $request);

        return $this->ok($paginator, 'Roles');
    }

    /**
     * POST /api/admin/roles
     * Body:
     * {
     *   "name": "provider_admin",
     *   "guard_name": "provider_api",
     *   "permissions": ["users.view", "users.create"]
     * }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'guard_name' => ['required', Rule::in(['admin_api', 'provider_api'])],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:150'],
        ]);

        $guard = $data['guard_name'];

        // Unique per guard
        $request->validate([
            'name' => [
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('guard_name', $guard)),
            ],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => $guard,
        ]);

        if (!empty($data['permissions'])) {
            $perms = Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $data['permissions'])
                ->get();

            $role->syncPermissions($perms);
        }

        $this->forgetPermissionCache();

        return $this->ok(
            $role->load('permissions:id,name,guard_name'),
            'Role created',
            201
        );
    }

    /**
     * GET /api/admin/roles/{id}?guard_name=...
     */
    public function show(Request $request, Role $role)
    {
        $guard = $this->resolveGuard($request);

        if ($role->guard_name !== $guard) {
            return $this->fail('Role not found for this guard', null, 404);
        }

        return $this->ok($role->load('permissions:id,name,guard_name'), 'Role');
    }

    /**
     * PUT /api/admin/roles/{id}
     * Body:
     * {
     *   "name": "provider_admin_v2",
     *   "permissions": ["users.view"]
     * }
     */
    public function update(Request $request, Role $role)
    {
        $guard = $this->resolveGuard($request);

        if ($role->guard_name !== $guard) {
            return $this->fail('Role not found for this guard', null, 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:150'],
        ]);

        if (array_key_exists('name', $data)) {
            $request->validate([
                'name' => [
                    Rule::unique('roles', 'name')
                        ->ignore($role->id)
                        ->where(fn ($q) => $q->where('guard_name', $guard)),
                ],
            ]);

            $role->name = $data['name'];
            $role->save();
        }

        if (array_key_exists('permissions', $data)) {
            $perms = Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $data['permissions'])
                ->get();

            $role->syncPermissions($perms);
        }

        $this->forgetPermissionCache();

        return $this->ok($role->load('permissions:id,name,guard_name'), 'Role updated');
    }

    /**
     * DELETE /api/admin/roles/{id}?guard_name=...
     */
    public function destroy(Request $request, Role $role)
    {
        $guard = $this->resolveGuard($request);

        if ($role->guard_name !== $guard) {
            return $this->fail('Role not found for this guard', null, 404);
        }

        // Optional: protect critical roles
        if ($role->name === 'super_admin' && $guard === 'admin_api') {
            return $this->fail('Cannot delete super_admin role', null, 403);
        }

        $role->delete();
        $this->forgetPermissionCache();

        return $this->ok(null, 'Role deleted');
    }

    private function resolveGuard(Request $request): string
    {
        // allow guard_name via query; default to admin_api
        $guard = $request->query('guard_name', 'admin_api');

        if (!in_array($guard, ['admin_api', 'provider_api'], true)) {
            // if invalid, default safely to admin_api
            $guard = 'admin_api';
        }

        return $guard;
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
