<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait WithPerPagePagination
{
    /**
     * Validate and resolve pagination inputs.
     */
    protected function resolvePagination(Request $request, int $defaultPerPage = 20, int $maxPerPage = 200): array
    {
        $data = $request->validate([
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:' . $maxPerPage],
        ]);

        return [
            'per_page' => $data['per_page'] ?? $defaultPerPage,
        ];
    }

    /**
     * Paginate a query builder using ?page= & ?per_page=.
     * Laravel automatically picks up ?page=, we only control per_page here.
     */
    protected function paginate(Builder $query, Request $request, int $defaultPerPage = 20, int $maxPerPage = 200): LengthAwarePaginator
    {
        $pagination = $this->resolvePagination($request, $defaultPerPage, $maxPerPage);

        return $query
            ->paginate($pagination['per_page'])
            ->appends($request->query());
    }
}