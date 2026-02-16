<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\DiagnosisCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IcdLookupController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/provider/icd-lookup?q=J15&page=1&per_page=20
     *
     * Returns DISTINCT diagnosis codes from diagnosis_codes,
     * does NOT expose any mappings back to cases.
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

        $query = DiagnosisCode::query()
            ->selectRaw('code, max(long_description) as description, max(short_description) as short_description')
            ->groupBy('code')
            ->orderBy('code');

        if (!empty($data['q'])) {
            $q = trim($data['q']);
            $query->where(function ($qq) use ($q) {
                $qq->where('code', 'ilike', "%{$q}%")
                   ->orWhere('long_description', 'ilike', "%{$q}%")
                   ->orWhere('short_description', 'ilike', "%{$q}%");
            });
        }

        $paginator = $this->paginate($query, $request);

        $paginator->getCollection()->transform(function ($row) {
            return [
                'code' => $row->code,
                'description' => $row->description,
                'short_description' => $row->short_description,
            ];
        });

        return $this->ok($paginator, 'ICD lookup');
    }
}
