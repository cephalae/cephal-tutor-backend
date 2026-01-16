<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProviderUserController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    public function index(Request $request, Provider $provider)
    {
        $paginator = $this->paginate(
            User::query()
                ->where('provider_id', $provider->id)
                ->latest(),
            $request
        );

        return $this->ok($paginator, 'Provider users');
    }

    public function store(Request $request, Provider $provider)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['required', 'string', 'max:20', 'unique:users,mobile'],
            'password' => ['required', 'string', 'min:8'],
            'type' => ['required', 'in:provider_admin,student,provider_user'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'password' => Hash::make($data['password']),
            'type' => $data['type'],
            'provider_id' => $provider->id,
        ]);


        return $this->ok($user, 'Provider user created', 201);
    }

    public function show(Provider $provider, User $user)
    {
        if ($user->provider_id !== $provider->id) {
            return $this->fail('User does not belong to this provider', null, 404);
        }

        return $this->ok($user, 'Provider user');
    }

    public function update(Request $request, Provider $provider, User $user)
    {
        if ($user->provider_id !== $provider->id) {
            return $this->fail('User does not belong to this provider', null, 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'max:50', 'alpha_dash', 'unique:users,username,' . $user->id],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'mobile' => ['sometimes', 'string', 'max:20', 'unique:users,mobile,' . $user->id],
            'password' => ['nullable', 'string', 'min:8'],
            'type' => ['sometimes', 'in:provider_admin,student,provider_user'],
        ]);

        if (isset($data['password']) && $data['password']) {
            $data['password'] = \Illuminate\Support\Facades\Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return $this->ok($user, 'Provider user updated');
    }

    public function destroy(Provider $provider, User $user)
    {
        if ($user->provider_id !== $provider->id) {
            return $this->fail('User does not belong to this provider', null, 404);
        }

        $user->delete();
        return $this->ok(null, 'Provider user deleted');
    }
}
