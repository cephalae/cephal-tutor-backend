<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UnifiedAuthController extends Controller
{
    use ApiResponds;


    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 0) Identify account type first (prevents admin guard from authenticating provider users)
        $account = User::query()
            ->where('email', $data['email'])
            ->first();

        if (!$account) {
            return $this->fail('Invalid credentials', null, 401);
        }

        $guard = null;

        if ($account->type === 'admin') {
            $guard = 'admin_api';
        } elseif (in_array($account->type, ['provider_admin', 'student', 'provider_user'], true)) {
            $guard = 'provider_api';
        } else {
            return $this->fail('Account type not allowed', ['type' => $account->type], 401);
        }

        if (! $token = Auth::guard($guard)->attempt($data)) {
            return $this->fail('Invalid credentials', null, 401);
        }

        $user = Auth::guard($guard)->user();

        // Safety checks (keep these)
        if ($guard === 'admin_api') {
            if (!$user || $user->type !== 'admin') {
                Auth::guard($guard)->logout();
                return $this->fail('Not an admin account', null, 401);
            }
        }

        if ($guard === 'provider_api') {
            if (
                !$user ||
                !in_array($user->type, ['provider_admin', 'student', 'provider_user'], true) ||
                !$user->provider_id
            ) {
                Auth::guard($guard)->logout();
                return $this->fail('Not a provider user account', null, 401);
            }
        }

        return $this->ok($this->tokenPayload($token, $guard), 'Logged in');
    }

    /**
     * POST /api/auth/refresh
     * Header: Authorization: Bearer <token>
     * Body: { "auth_guard": "admin_api" | "provider_api" }
     */
    public function refresh(Request $request)
    {
        $data = $request->validate([
            'auth_guard' => ['required', 'in:admin_api,provider_api'],
        ]);

        $guard = $data['auth_guard'];

        try {
            $token = Auth::guard($guard)->refresh();
            $user  = Auth::guard($guard)->user();

            // Safety checks so a wrong guard can't be used accidentally
            if ($guard === 'admin_api') {
                if (!$user || $user->type !== 'admin') {
                    Auth::guard($guard)->logout();
                    return $this->fail('Not an admin token', null, 401);
                }
            }

            if ($guard === 'provider_api') {
                if (
                    !$user ||
                    !in_array($user->type, ['provider_admin', 'student', 'provider_user'], true) ||
                    !$user->provider_id
                ) {
                    Auth::guard($guard)->logout();
                    return $this->fail('Not a provider token', null, 401);
                }
            }

            return $this->ok($this->tokenPayload($token, $guard), 'Token refreshed');
        } catch (\Throwable $e) {
            return $this->fail('Token refresh failed', ['error' => $e->getMessage()], 401);
        }
    }

    private function tokenPayload(string $token, string $guard): array
    {
        $ttl = (int) config('jwt.ttl'); // minutes
        $user = Auth::guard($guard)->user();

        return [
            'auth_guard' => $guard,
            'login_as' => $this->resolveLoginAs($user),
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
            'user' => $user,
            'rbac' => $this->rbacPayload($user),
        ];
    }

    private function resolveLoginAs($user): string
    {
        $type = (string) ($user->type ?? '');

        return match ($type) {
            'admin' => 'admin',
            'provider_admin' => 'provider_admin',
            'student' => 'student',
            'provider_user' => 'provider_user',
            default => 'unknown',
        };
    }

    private function rbacPayload($user): array
    {
        // Works if spatie/permission is installed + HasRoles on User.
        // If not, returns empty arrays safely.
        try {
            $roles = method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->values()->all()
                : [];

            $permissions = method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')->values()->all()
                : [];
        } catch (\Throwable $e) {
            $roles = [];
            $permissions = [];
        }

        return [
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }
}
