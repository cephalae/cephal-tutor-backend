<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\RecordCategory;
use App\Models\StudentRecordAssignment;
use App\Models\StudentRecordAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProviderAdminDashboardController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    private function ensureProviderAdmin()
    {
        $me = Auth::guard('provider_api')->user();

        if (!$me || !$me->provider_id) {
            abort(response()->json([
                'success' => false,
                'message' => 'Provider context missing',
                'errors' => null,
            ], 403));
        }

        // Simple: type check
        if ($me->type !== 'provider_admin') {
            abort(response()->json([
                'success' => false,
                'message' => 'Only provider admins can access this endpoint',
                'errors' => null,
            ], 403));
        }

        return $me;
    }

    private function resolveDateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'date_format:Y-m-d'],
        ]);

        $from = !empty($data['from']) ? Carbon::createFromFormat('Y-m-d', $data['from'])->startOfDay() : null;
        $to   = !empty($data['to'])   ? Carbon::createFromFormat('Y-m-d', $data['to'])->endOfDay() : null;

        if ($from && $to && $from->gt($to)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Invalid date range: from must be <= to',
                'errors'  => null,
            ], 422));
        }

        return [$from, $to];
    }

    private function applyDateFilter($query, string $column, ?Carbon $from, ?Carbon $to)
    {
        if ($from) $query->where($column, '>=', $from);
        if ($to)   $query->where($column, '<=', $to);
        return $query;
    }

    private function filtersPayload(?Carbon $from, ?Carbon $to): array
    {
        return [
            'from' => $from?->toDateString(),
            'to'   => $to?->toDateString(),
            'default_all_time' => ($from === null && $to === null),
        ];
    }

    /**
     * GET /api/provider/admin/dashboard/summary?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * NOTE:
     * - Attempt metrics are filtered by attempts.created_at (activity timeframe).
     * - Assignment status totals are filtered by assignments.created_at (allocation timeframe).
     */
    public function summary(Request $request)
    {
        $me = $this->ensureProviderAdmin();
        [$from, $to] = $this->resolveDateRange($request);

        // Students in provider
        $studentsQuery = User::query()
            ->where('provider_id', $me->provider_id)
            ->where('type', 'student');

        $totalStudents = (int) $studentsQuery->count();

        // Active students = students who attempted in timeframe (or all-time if no filter)
        $attemptsBase = StudentRecordAttempt::query()
            ->whereIn('student_id', (clone $studentsQuery)->select('id'));

        $this->applyDateFilter($attemptsBase, 'created_at', $from, $to);

        $activeStudents = (int) (clone $attemptsBase)
            ->distinct('student_id')
            ->count('student_id');

        $attemptStats = (clone $attemptsBase)
            ->selectRaw("
                count(*) as total_attempts,
                sum(case when is_correct = true then 1 else 0 end) as correct_attempts,
                sum(case when attempt_no = 1 then 1 else 0 end) as first_try_total,
                sum(case when attempt_no = 1 and is_correct = true then 1 else 0 end) as first_try_correct
            ")
            ->first();

        $totalAttempts = (int) ($attemptStats->total_attempts ?? 0);
        $correctAttempts = (int) ($attemptStats->correct_attempts ?? 0);
        $accuracy = $totalAttempts > 0 ? round(($correctAttempts / $totalAttempts) * 100, 2) : 0.0;

        $firstTryTotal = (int) ($attemptStats->first_try_total ?? 0);
        $firstTryCorrect = (int) ($attemptStats->first_try_correct ?? 0);
        $firstTryAccuracy = $firstTryTotal > 0 ? round(($firstTryCorrect / $firstTryTotal) * 100, 2) : 0.0;

        // Assignment status totals (allocation-based)
        $assignmentsBase = StudentRecordAssignment::query()
            ->whereIn('student_id', (clone $studentsQuery)->select('id'));

        $this->applyDateFilter($assignmentsBase, 'created_at', $from, $to);

        $assignmentStats = (clone $assignmentsBase)
            ->selectRaw("
                count(*) as assigned_total,
                sum(case when status = 'completed' then 1 else 0 end) as completed,
                sum(case when status = 'locked' then 1 else 0 end) as locked,
                avg(attempts_used::float) as avg_attempts_used
            ")
            ->first();

        $assignedTotal = (int) ($assignmentStats->assigned_total ?? 0);
        $completed = (int) ($assignmentStats->completed ?? 0);
        $locked = (int) ($assignmentStats->locked ?? 0);
        $remaining = max(0, $assignedTotal - $completed - $locked);

        // Attempts distribution by assignments (overall)
        $dist = (clone $assignmentsBase)
            ->selectRaw("
                sum(case when status = 'completed' and attempts_used = 1 then 1 else 0 end) as solved_1,
                sum(case when status = 'completed' and attempts_used = 2 then 1 else 0 end) as solved_2,
                sum(case when status = 'completed' and attempts_used = 3 then 1 else 0 end) as solved_3,
                sum(case when status = 'locked' then 1 else 0 end) as locked
            ")
            ->first();

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to),
            'cards' => [
                'total_students' => $totalStudents,
                'active_students' => $activeStudents,
                'assigned_total' => $assignedTotal,
                'completed' => $completed,
                'remaining' => $remaining,
                'locked' => $locked,
                'total_attempts' => $totalAttempts,
                'accuracy_percent' => $accuracy,
                'first_try_accuracy_percent' => $firstTryAccuracy,
            ],
            'attempts_distribution' => [
                'solved_in_1' => (int) ($dist->solved_1 ?? 0),
                'solved_in_2' => (int) ($dist->solved_2 ?? 0),
                'solved_in_3' => (int) ($dist->solved_3 ?? 0),
                'locked' => (int) ($dist->locked ?? 0),
            ],
        ], 'Provider admin dashboard summary');
    }

    /**
     * GET /api/provider/admin/dashboard/category-progress?from=...&to=...
     * Uses assignments.created_at for timeframe (allocation-based).
     */
    public function categoryProgress(Request $request)
    {
        $me = $this->ensureProviderAdmin();
        [$from, $to] = $this->resolveDateRange($request);

        $studentsQuery = User::query()
            ->where('provider_id', $me->provider_id)
            ->where('type', 'student')
            ->select('id');

        $base = StudentRecordAssignment::query()
            ->whereIn('student_id', $studentsQuery);

        $this->applyDateFilter($base, 'created_at', $from, $to);

        $stats = (clone $base)
            ->selectRaw("
                category_id,
                count(*) as assigned_total,
                sum(case when status = 'completed' then 1 else 0 end) as completed,
                sum(case when status = 'locked' then 1 else 0 end) as locked
            ")
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        $categories = RecordCategory::query()->orderBy('name')->get(['id', 'name', 'slug']);

        $items = $categories->map(function ($cat) use ($stats) {
            $s = $stats->get($cat->id);
            $assigned = (int) ($s->assigned_total ?? 0);
            $completed = (int) ($s->completed ?? 0);
            $locked = (int) ($s->locked ?? 0);
            $remaining = max(0, $assigned - $completed - $locked);

            $lockedRate = $assigned > 0 ? round(($locked / $assigned) * 100, 2) : 0.0;
            $completionRate = $assigned > 0 ? round(($completed / $assigned) * 100, 2) : 0.0;

            return [
                'category_id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'assigned_total' => $assigned,
                'completed' => $completed,
                'locked' => $locked,
                'remaining' => $remaining,
                'locked_rate_percent' => $lockedRate,
                'completion_rate_percent' => $completionRate,
            ];
        });

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to),
            'items' => $items,
        ], 'Provider category progress');
    }

    /**
     * GET /api/provider/admin/dashboard/activity?from=...&to=...
     * Attempts-based (activity timeframe), grouped per day.
     */
    public function activity(Request $request)
    {
        $me = $this->ensureProviderAdmin();
        [$from, $to] = $this->resolveDateRange($request);

        $studentsQuery = User::query()
            ->where('provider_id', $me->provider_id)
            ->where('type', 'student')
            ->select('id');

        $q = StudentRecordAttempt::query()
            ->whereIn('student_id', $studentsQuery);

        $this->applyDateFilter($q, 'created_at', $from, $to);

        $rows = $q->selectRaw("
                date(created_at) as day,
                count(*) as attempts,
                sum(case when is_correct = true then 1 else 0 end) as correct
            ")
            ->groupByRaw("date(created_at)")
            ->orderByRaw("date(created_at)")
            ->get();

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to),
            'series' => $rows,
        ], 'Provider activity');
    }

    /**
     * GET /api/provider/admin/dashboard/mistakes?from=...&to=...&type=wrong|missing|both&limit=10
     * Attempts-based, explodes JSON arrays across provider students.
     */
    public function mistakes(Request $request)
    {
        $me = $this->ensureProviderAdmin();
        [$from, $to] = $this->resolveDateRange($request);

        $data = $request->validate([
            'type' => ['nullable', 'in:wrong,missing,both'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $type = $data['type'] ?? 'both';
        $limit = (int) ($data['limit'] ?? 10);

        $bindings = [
            'provider_id' => $me->provider_id,
        ];

        $dateSql = " u.provider_id = :provider_id AND u.type = 'student' ";

        if ($from) {
            $dateSql .= " AND a.created_at >= :from ";
            $bindings['from'] = $from->toDateTimeString();
        }
        if ($to) {
            $dateSql .= " AND a.created_at <= :to ";
            $bindings['to'] = $to->toDateTimeString();
        }

        $result = [];

        if ($type === 'wrong' || $type === 'both') {
            $sqlWrong = "
                select code, count(*) as count
                from (
                    select jsonb_array_elements_text(a.wrong_codes::jsonb) as code
                    from student_record_attempts a
                    join users u on u.id = a.student_id
                    where {$dateSql} and a.wrong_codes is not null
                ) t
                group by code
                order by count desc
                limit {$limit}
            ";
            $result['wrong_top'] = DB::select($sqlWrong, $bindings);
        }

        if ($type === 'missing' || $type === 'both') {
            $sqlMissing = "
                select code, count(*) as count
                from (
                    select jsonb_array_elements_text(a.missing_codes::jsonb) as code
                    from student_record_attempts a
                    join users u on u.id = a.student_id
                    where {$dateSql} and a.missing_codes is not null
                ) t
                group by code
                order by count desc
                limit {$limit}
            ";
            $result['missing_top'] = DB::select($sqlMissing, $bindings);
        }

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to),
            'limit' => $limit,
            'data' => $result,
        ], 'Provider mistakes');
    }

    /**
     * GET /api/provider/admin/dashboard/students?from=...&to=...&q=...&sort=...&direction=...&page=1&per_page=20
     *
     * Attempts-based metrics in timeframe.
     * Returns paginated students with performance columns.
     */
    public function students(Request $request)
    {
        $me = $this->ensureProviderAdmin();
        [$from, $to] = $this->resolveDateRange($request);

        $allowedSorts = ['name', 'email', 'username', 'mobile', 'last_attempt_at', 'accuracy_percent', 'attempts'];
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:' . implode(',', $allowedSorts)],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $students = User::query()
            ->where('provider_id', $me->provider_id)
            ->where('type', 'student')
            ->select(['id', 'name', 'email', 'username', 'mobile', 'provider_id']);

        if (!empty($data['q'])) {
            $q = trim($data['q']);
            $students->where(function ($qq) use ($q) {
                $qq->where('name', 'ilike', "%{$q}%")
                   ->orWhere('email', 'ilike', "%{$q}%")
                   ->orWhere('username', 'ilike', "%{$q}%")
                   ->orWhere('mobile', 'ilike', "%{$q}%");
            });
        }

        // Paginate students first (cheap), then compute metrics for that page
        $paginator = $this->paginate($students->orderBy('id', 'desc'), $request);

        $studentIds = collect($paginator->items())->pluck('id')->all();
        if (empty($studentIds)) {
            return $this->ok([
                'filters' => $this->filtersPayload($from, $to),
                'data' => $paginator,
            ], 'Students');
        }

        // Attempts metrics in timeframe for the page students
        $attemptsQ = StudentRecordAttempt::query()
            ->whereIn('student_id', $studentIds);
        $this->applyDateFilter($attemptsQ, 'created_at', $from, $to);

        $attemptAgg = (clone $attemptsQ)
            ->selectRaw("
                student_id,
                count(*) as attempts,
                sum(case when is_correct = true then 1 else 0 end) as correct,
                max(created_at) as last_attempt_at
            ")
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $firstTryAgg = (clone $attemptsQ)
            ->where('attempt_no', 1)
            ->selectRaw("
                student_id,
                count(*) as first_try_total,
                sum(case when is_correct = true then 1 else 0 end) as first_try_correct
            ")
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        // Assignment stats (all-time, OR you can date-filter by assignment created_at if desired)
        $assignQ = StudentRecordAssignment::query()
            ->whereIn('student_id', $studentIds);

        $assignAgg = (clone $assignQ)
            ->selectRaw("
                student_id,
                count(*) as assigned_total,
                sum(case when status = 'completed' then 1 else 0 end) as completed,
                sum(case when status = 'locked' then 1 else 0 end) as locked
            ")
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        // Transform paginator items to include metrics
        $items = collect($paginator->items())->map(function ($s) use ($attemptAgg, $firstTryAgg, $assignAgg) {
            $a = $attemptAgg->get($s['id']);
            $f = $firstTryAgg->get($s['id']);
            $asg = $assignAgg->get($s['id']);

            $attempts = (int) ($a->attempts ?? 0);
            $correct = (int) ($a->correct ?? 0);
            $accuracy = $attempts > 0 ? round(($correct / $attempts) * 100, 2) : 0.0;

            $ftTotal = (int) ($f->first_try_total ?? 0);
            $ftCorrect = (int) ($f->first_try_correct ?? 0);
            $ftAccuracy = $ftTotal > 0 ? round(($ftCorrect / $ftTotal) * 100, 2) : 0.0;

            $assigned = (int) ($asg->assigned_total ?? 0);
            $completed = (int) ($asg->completed ?? 0);
            $locked = (int) ($asg->locked ?? 0);

            return [
                'id' => $s['id'],
                'name' => $s['name'],
                'email' => $s['email'],
                'username' => $s['username'],
                'mobile' => $s['mobile'],

                'assigned_total' => $assigned,
                'completed' => $completed,
                'locked' => $locked,
                'remaining' => max(0, $assigned - $completed - $locked),

                'attempts' => $attempts,
                'accuracy_percent' => $accuracy,
                'first_try_accuracy_percent' => $ftAccuracy,
                'last_attempt_at' => $a->last_attempt_at ?? null,
            ];
        });

        // Optional: sort the current page by computed fields
        $sort = $data['sort'] ?? null;
        $direction = $data['direction'] ?? 'desc';

        if ($sort && in_array($sort, ['accuracy_percent', 'attempts', 'last_attempt_at'], true)) {
            $items = $items->sortBy($sort, SORT_REGULAR, $direction === 'desc')->values();
        }

        // Replace paginator items with transformed ones
        $paginator->setCollection($items);

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to),
            'data' => $paginator,
        ], 'Students');
    }
}
