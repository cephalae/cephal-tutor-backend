<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\DiagnosisCategory;
use App\Models\DiagnosisCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DiagnosisCategoryController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/provider/diagnosis-categories?parent_id=1&page=1&per_page=50
     * parent_id omitted/null => root nodes.
     */
    public function index(Request $request)
    {
        $me = Auth::guard('provider_api')->user();
        if (!in_array($me->type, ['student', 'provider_admin', 'provider_user'], true)) {
            return $this->fail('Forbidden', null, 403);
        }

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = DiagnosisCategory::query()
            ->where('is_active', true)
            ->when(empty($data['parent_id']), fn($q) => $q->whereNull('parent_id'))
            ->when(!empty($data['parent_id']), fn($q) => $q->where('parent_id', (int)$data['parent_id']))
            ->withCount(['children', 'diagnosisCodes'])
            ->orderByRaw('sort_order IS NULL, sort_order ASC')
            ->orderBy('category_name');

        $paginator = $this->paginate($query, $request, defaultPerPage: 50, maxPerPage: 200);

        $paginator->getCollection()->transform(function (DiagnosisCategory $c) {
            return [
                'id' => $c->id,
                'parent_id' => $c->parent_id,
                'category_name' => $c->category_name,
                'description' => $c->description,
                'keyword' => $c->keyword,
                'depth' => $c->depth,
                'path' => $c->path,
                'path_label' => $c->path_label,
                'children_count' => $c->children_count ?? 0,
                'codes_count' => $c->diagnosis_codes_count ?? 0,
            ];
        });

        return $this->ok($paginator, 'Diagnosis categories');
    }

    /**
     * GET /api/provider/diagnosis-categories/{id}/codes?page=1&per_page=50
     */
    public function codes(Request $request, int $id)
    {
        $me = Auth::guard('provider_api')->user();
        if (!in_array($me->type, ['student', 'provider_admin', 'provider_user'], true)) {
            return $this->fail('Forbidden', null, 403);
        }

        // Ensure category exists (optional)
        $category = DiagnosisCategory::query()->find($id);
        if (!$category) {
            return $this->fail('Category not found', null, 404);
        }

        $query = DiagnosisCode::query()
            ->where('category_id', $id)
            ->orderBy('code');

        $paginator = $this->paginate($query, $request, defaultPerPage: 50, maxPerPage: 200);

        $paginator->getCollection()->transform(function (DiagnosisCode $row) {
            return [
                'id' => $row->id,
                'category_id' => $row->category_id,
                'code' => $row->code,
                'long_description' => $row->long_description,
                'short_description' => $row->short_description,
            ];
        });

        return $this->ok([
            'category' => [
                'id' => $category->id,
                'category_name' => $category->category_name,
                'path_label' => $category->path_label,
            ],
            'codes' => $paginator,
        ], 'Diagnosis codes');
    }
}
