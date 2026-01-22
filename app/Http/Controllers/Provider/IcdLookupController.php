<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\MedicalRecordCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IcdLookupController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/provider/icd-lookup?q=J15&page=1&per_page=20
     *
     * Returns DISTINCT ICD codes from your answer-key table,
     * but does NOT expose record_id / comments / mapping back to cases.
     */
    public function index(Request $request)
    {
        $me = Auth::guard('provider_api')->user();

        if (!in_array($me->type, ['student', 'provider_admin', 'provider_user'], true)) {
            return $this->fail('Forbidden', null, 403);
        }

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = MedicalRecordCode::query()
            ->selectRaw('code, max(description) as description')
            ->groupBy('code')
            ->orderBy('code');

        if (!empty($data['q'])) {
            $q = trim($data['q']);

            // Postgres: case-insensitive search
            $query->where(function ($qq) use ($q) {
                $qq->where('code', 'ilike', "%{$q}%")
                   ->orWhere('description', 'ilike', "%{$q}%");
            });
        }

        // Paginate the grouped results
        $paginator = $this->paginate($query, $request);

        // Ensure response contains only safe fields
        $paginator->getCollection()->transform(function ($row) {
            return [
                'code' => $row->code,
                'description' => $row->description,
            ];
        });

        return $this->ok($paginator, 'ICD lookup');
    }
}
