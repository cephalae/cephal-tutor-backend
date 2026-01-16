<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ProviderUserManagementController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/provider/users?page=&per_page=
     * List users only within authenticated user's provider_id.
     */
    public function index(Request $request)
    {
        $me = Auth::guard('provider_api')->user();

        if (!$me->provider_id) {
            return $this->fail('Provider context missing', null, 403);
        }

        $query = User::query()
            ->where('provider_id', $me->provider_id)
            ->whereIn('type', ['provider_admin', 'student', 'provider_user'])
            ->latest();

        $paginator = $this->paginate($query, $request);

        return $this->ok($paginator, 'Provider users');
    }

    /**
     * POST /api/provider/users
     * Create a user within the same provider_id as the authenticated provider user.
     */
    public function store(Request $request)
    {
        $me = Auth::guard('provider_api')->user();

        if (!$me->provider_id) {
            return $this->fail('Provider context missing', null, 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['required', 'string', 'max:20', 'unique:users,mobile'],
            'password' => ['required', 'string', 'min:8'],

            // IMPORTANT: provider admins can only create provider-side types
            'type' => ['required', Rule::in(['provider_admin', 'student', 'provider_user'])],

            // Optional roles assignment (provider_api only)
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'max:100'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'password' => Hash::make($data['password']),
            'type' => $data['type'],
            'provider_id' => $me->provider_id,
        ]);

        // Optional: sync roles (provider guard only)
        if (!empty($data['roles'])) {
            $this->syncProviderRoles($user, $data['roles']);
        }

        $this->forgetPermissionCache();

        return $this->ok($user->fresh(), 'Provider user created', 201);
    }

    /**
     * GET /api/provider/users/{user}
     * Show user only if user.provider_id == my.provider_id
     */
    public function show(User $user)
    {
        $me = Auth::guard('provider_api')->user();

        if (!$this->sameProvider($me, $user)) {
            return $this->fail('User not found', null, 404);
        }

        return $this->ok($this->userWithRbac($user), 'Provider user');
    }

    /**
     * PUT /api/provider/users/{user}
     * Update user only within same provider
     */
    public function update(Request $request, User $user)
    {
        $me = Auth::guard('provider_api')->user();

        if (!$this->sameProvider($me, $user)) {
            return $this->fail('User not found', null, 404);
        }

        // Prevent editing an admin user in case someone tries to guess ID
        if ($user->type === 'admin') {
            return $this->fail('Forbidden', null, 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:50', 'alpha_dash', 'unique:users,username,' . $user->id],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'mobile' => ['sometimes', 'string', 'max:20', 'unique:users,mobile,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'type' => ['sometimes', Rule::in(['provider_admin', 'student', 'provider_user'])],

            // Optional: sync roles (provider_api only)
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

        $user->update($data);

        if (array_key_exists('roles', $data)) {
            $this->syncProviderRoles($user, $data['roles'] ?? []);
        }

        $this->forgetPermissionCache();

        return $this->ok($this->userWithRbac($user->fresh()), 'Provider user updated');
    }

    /**
     * DELETE /api/provider/users/{user}
     * Delete user only within same provider
     */
    public function destroy(User $user)
    {
        $me = Auth::guard('provider_api')->user();

        if (!$this->sameProvider($me, $user)) {
            return $this->fail('User not found', null, 404);
        }

        // Optional: prevent provider admin from deleting themselves
        if ($me->id === $user->id) {
            return $this->fail('You cannot delete your own account', null, 422);
        }

        // Prevent deleting system admin user, just in case
        if ($user->type === 'admin') {
            return $this->fail('Forbidden', null, 403);
        }

        $user->delete();
        $this->forgetPermissionCache();

        return $this->ok(null, 'Provider user deleted');
    }

    // ----------------- helpers -----------------

    private function sameProvider(User $me, User $target): bool
    {
        return (int) $me->provider_id > 0
            && (int) $target->provider_id === (int) $me->provider_id
            && in_array($target->type, ['provider_admin', 'student', 'provider_user'], true);
    }

    private function syncProviderRoles(User $user, array $roleNames): void
    {
        // Only provider_api roles are allowed here
        $roles = Role::query()
            ->where('guard_name', 'provider_api')
            ->whereIn('name', $roleNames)
            ->get();

        if ($roles->count() !== count($roleNames)) {
            // If you prefer strict errors, throw validation exception.
            // Here, fail hard:
            abort(response()->json([
                'success' => false,
                'message' => 'One or more roles are invalid for provider_api guard',
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
