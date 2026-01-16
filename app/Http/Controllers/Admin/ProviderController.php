<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\Provider;
use Illuminate\Http\Request;

class ProviderController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    public function index(Request $request)
    {
        $paginator = $this->paginate(
            Provider::query()->latest(),
            $request
        );

        return $this->ok($paginator, 'Providers');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:providers,code'],
        ]);

        $provider = Provider::create($data);

        return $this->ok($provider, 'Provider created', 201);
    }

    public function show(Provider $provider)
    {
        return $this->ok($provider, 'Provider');
    }

    public function update(Request $request, Provider $provider)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['sometimes', 'string', 'max:50', 'unique:providers,code,' . $provider->id],
        ]);

        $provider->update($data);

        return $this->ok($provider, 'Provider updated');
    }

    public function destroy(Provider $provider)
    {
        $provider->delete();
        return $this->ok(null, 'Provider deleted');
    }
}
