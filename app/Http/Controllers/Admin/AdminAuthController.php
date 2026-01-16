<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    use ApiResponds;

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Attempt login on admin guard
        if (! $token = Auth::guard('admin_api')->attempt($data)) {
            return $this->fail('Invalid credentials', null, 401);
        }

        $user = Auth::guard('admin_api')->user();
        if ($user->type !== 'admin') {
            Auth::guard('admin_api')->logout();
            return $this->fail('Not an admin account', null, 403);
        }

        return $this->ok($this->tokenPayload($token, 'admin_api'), 'Logged in');
    }

    public function me()
    {
        return $this->ok(Auth::guard('admin_api')->user(), 'Me');
    }

    public function logout()
    {
        Auth::guard('admin_api')->logout();
        return $this->ok(null, 'Logged out');
    }

    public function refresh()
    {
        $token = Auth::guard('admin_api')->refresh();
        return $this->ok($this->tokenPayload($token, 'admin_api'), 'Token refreshed');
    }

    private function tokenPayload(string $token, string $guard): array
    {
        $ttl = (int) config('jwt.ttl'); // minutes
        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
            'user' => Auth::guard($guard)->user(),
        ];
    }
}
