<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/admin/permissions?guard_name=admin_api|provider_api&page=&per_page=
     */
    public function index(Request $request)
    {
        $guard = $request->query('guard_name', 'admin_api');

        if (!in_array($guard, ['admin_api', 'provider_api'], true)) {
            return $this->fail('Invalid guard_name. Use admin_api or provider_api.', null, 422);
        }

        $query = Permission::query()
            ->where('guard_name', $guard)
            ->orderBy('name');

        // Optional: quick search
        if ($search = $request->query('q')) {
            $query->where('name', 'ilike', '%' . $search . '%'); // Postgres
        }

        $paginator = $this->paginate($query, $request);

        return $this->ok($paginator, 'Permissions');
    }
}
