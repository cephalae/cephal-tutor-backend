<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProviderAuthController extends Controller
{
    use ApiResponds;

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $token = Auth::guard('provider_api')->attempt($data)) {
            return $this->fail('Invalid credentials', null, 401);
        }

        $user = Auth::guard('provider_api')->user();

        // must be a provider user AND belong to a provider
        if (! in_array($user->type, ['provider_admin', 'student', 'provider_user'], true) || !$user->provider_id) {
            Auth::guard('provider_api')->logout();
            return $this->fail('Not a provider user account', null, 403);
        }

        return $this->ok($this->tokenPayload($token, 'provider_api'), 'Logged in');
    }

    public function me()
    {
        return $this->ok(Auth::guard('provider_api')->user(), 'Me');
    }

    public function logout()
    {
        Auth::guard('provider_api')->logout();
        return $this->ok(null, 'Logged out');
    }

    public function refresh()
    {
        $token = Auth::guard('provider_api')->refresh();
        return $this->ok($this->tokenPayload($token, 'provider_api'), 'Token refreshed');
    }

    private function tokenPayload(string $token, string $guard): array
    {
        $ttl = (int) config('jwt.ttl');
        return [
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $ttl * 60,
            'user' => Auth::guard($guard)->user(),
        ];
    }
}
