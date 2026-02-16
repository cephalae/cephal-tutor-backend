<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\DiagnosisCategory;
use App\Models\DiagnosisCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DiagnosisSearchController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/provider/diagnosis-search?q=cholera&page=1&per_page=20
     *
     * Searches BOTH:
     * - categories: category_name, description, keyword, path_label
     * - codes: code, long_description, short_description
     *
     * Returns a mixed, paginated list of:
     * - type=category
     * - type=code
     */
    public function index(Request $request)
    {
        $me = Auth::guard('provider_api')->user();
        if (!in_array($me->type, ['student', 'provider_admin', 'provider_user'], true)) {
            return $this->fail('Forbidden', null, 403);
        }

        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $q = trim($data['q']);
        $like = "%{$q}%";

        // Validate pagination using your trait (we'll paginate query builder manually)
        $pagination = $this->resolvePagination($request, defaultPerPage: 20, maxPerPage: 200);

        // Categories subquery
        $cat = DiagnosisCategory::query()
            ->where('is_active', true)
            ->where(function ($qq) use ($like) {
                $qq->where('category_name', 'ilike', $like)
                    ->orWhere('description', 'ilike', $like)
                    ->orWhere('keyword', 'ilike', $like)
                    ->orWhere('path_label', 'ilike', $like);
            })
            ->selectRaw("
                'category' as type,
                diagnosis_categories.id as id,
                diagnosis_categories.parent_id as parent_id,
                diagnosis_categories.category_name as category_name,
                NULL::text as code,
                diagnosis_categories.description as long_description,
                NULL::text as short_description,
                diagnosis_categories.path_label as path_label,
                diagnosis_categories.depth as depth,
                diagnosis_categories.path as path
            ");

        // Codes subquery (join category for path_label/breadcrumb)
        $code = DiagnosisCode::query()
            ->join('diagnosis_categories as dc', 'dc.id', '=', 'diagnosis_codes.category_id')
            ->where(function ($qq) use ($like) {
                $qq->where('diagnosis_codes.code', 'ilike', $like)
                    ->orWhere('diagnosis_codes.long_description', 'ilike', $like)
                    ->orWhere('diagnosis_codes.short_description', 'ilike', $like);
            })
            ->selectRaw("
                'code' as type,
                diagnosis_codes.id as id,
                diagnosis_codes.category_id as parent_id,
                dc.category_name as category_name,
                diagnosis_codes.code as code,
                diagnosis_codes.long_description as long_description,
                diagnosis_codes.short_description as short_description,
                dc.path_label as path_label,
                dc.depth as depth,
                dc.path as path
            ");

        // UNION ALL and wrap (so ORDER BY applies properly)
        $union = $cat->toBase()->unionAll($code->toBase());

        $wrapped = DB::query()
            ->fromSub($union, 'u')
            ->orderByRaw("CASE WHEN type = 'category' THEN 0 ELSE 1 END") // categories first
            ->orderBy('category_name')
            ->orderBy('code');

        $paginator = $wrapped
            ->paginate($pagination['per_page'])
            ->appends($request->query());

        // return safe fields only
        $items = collect($paginator->items())->map(function ($r) {
            return [
                'type' => $r->type, // category|code
                'id' => (int)$r->id,
                'parent_id' => $r->parent_id !== null ? (int)$r->parent_id : null,
                'category_name' => $r->category_name,
                'code' => $r->code, // only for type=code
                'long_description' => $r->long_description,
                'short_description' => $r->short_description,
                'path_label' => $r->path_label,
                'depth' => (int)$r->depth,
                'path' => $r->path,
            ];
        });

        // Replace paginator items with mapped items
        $paginator->setCollection($items);

        return $this->ok($paginator, 'Diagnosis search');
    }
}
