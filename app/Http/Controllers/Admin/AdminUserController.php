<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminUserController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/admin/admin-users?page=&per_page=&q=
     */
    public function index(Request $request)
    {
        $query = User::query()
            ->where('type', 'admin')
            ->whereNull('provider_id')
            ->latest();

        if ($q = $request->query('q')) {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%")
                    ->orWhere('username', 'ilike', "%{$q}%")
                    ->orWhere('mobile', 'ilike', "%{$q}%");
            });
        }

        $paginator = $this->paginate($query, $request);

        // Optionally load roles for list (can be heavy). Enable if you want:
        // $paginator->getCollection()->load('roles');

        return $this->ok($paginator, 'Admin users');
    }

    /**
     * POST /api/admin/admin-users
     * {
     *   "name": "...",
     *   "username": "...",
     *   "email": "...",
     *   "mobile": "...",
     *   "password": "...",
     *   "roles": ["admin"] // optional
     * }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['required', 'string', 'max:20', 'unique:users,mobile'],
            'password' => ['required', 'string', 'min:8'],

            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'max:100'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'password' => Hash::make($data['password']),
            'type' => 'admin',
            'provider_id' => null,
        ]);

        if (!empty($data['roles'])) {
            $this->syncAdminRoles($user, $data['roles']);
        }

        $this->forgetPermissionCache();

        return $this->ok($this->userWithRbac($user->fresh()), 'Admin user created', 201);
    }

    /**
     * GET /api/admin/admin-users/{user}
     */
    public function show(User $admin_user)
    {
        if (!$this->isAdminUser($admin_user)) {
            return $this->fail('Admin user not found', null, 404);
        }

        return $this->ok($this->userWithRbac($admin_user), 'Admin user');
    }

    /**
     * PUT /api/admin/admin-users/{user}
     * {
     *   "name": "...",
     *   "mobile": "...",
     *   "password": "..." (optional),
     *   "roles": ["super_admin"] (optional: replaces all roles)
     * }
     */
    public function update(Request $request, User $admin_user)
    {
        if (!$this->isAdminUser($admin_user)) {
            return $this->fail('Admin user not found', null, 404);
        }

        // Optional: protect main admin email
        if ($admin_user->email === 'admin@example.com' && $request->hasAny(['email', 'type', 'provider_id'])) {
            return $this->fail('Cannot modify protected fields for main admin', null, 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:50', 'alpha_dash', 'unique:users,username,' . $admin_user->id],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $admin_user->id],
            'mobile' => ['sometimes', 'string', 'max:20', 'unique:users,mobile,' . $admin_user->id],
            'password' => ['nullable', 'string', 'min:8'],

            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'max:100'],
        ]);

        if (array_key_exists('password', $data)) {
            if ($data['password']) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
        }

        // force admin identity fields
        $data['type'] = 'admin';
        $data['provider_id'] = null;

        $admin_user->update($data);

        if (array_key_exists('roles', $data)) {
            $this->syncAdminRoles($admin_user, $data['roles'] ?? []);
        }

        $this->forgetPermissionCache();

        return $this->ok($this->userWithRbac($admin_user->fresh()), 'Admin user updated');
    }

    /**
     * DELETE /api/admin/admin-users/{user}
     */
    public function destroy(User $admin_user)
    {
        if (!$this->isAdminUser($admin_user)) {
            return $this->fail('Admin user not found', null, 404);
        }

        // prevent deleting main admin
        if ($admin_user->email === 'admin@example.com') {
            return $this->fail('Cannot delete main admin', null, 403);
        }

        $admin_user->delete();
        $this->forgetPermissionCache();

        return $this->ok(null, 'Admin user deleted');
    }

    // ---------------- Helpers ----------------

    private function isAdminUser(User $user): bool
    {
        return $user->type === 'admin' && $user->provider_id === null;
    }

    private function syncAdminRoles(User $user, array $roleNames): void
    {
        // Only allow admin_api roles
        $roles = Role::query()
            ->where('guard_name', 'admin_api')
            ->whereIn('name', $roleNames)
            ->get();

        if ($roles->count() !== count($roleNames)) {
            abort(response()->json([
                'success' => false,
                'message' => 'One or more roles are invalid for admin_api guard',
                'errors' => null,
            ], 422));
        }

        $user->syncRoles($roles);
    }

    private function userWithRbac(User $user): array
    {
        return [
            'user' => $user,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
