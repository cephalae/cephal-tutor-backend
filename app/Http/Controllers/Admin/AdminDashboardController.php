<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Models\Provider;
use App\Models\RecordCategory;
use App\Models\StudentRecordAssignment;
use App\Models\StudentRecordAttempt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    private function ensureAdmin()
    {
        $me = Auth::guard('admin_api')->user();

        if (!$me || $me->type !== 'admin') {
            abort(response()->json([
                'success' => false,
                'message' => 'Only admins can access this endpoint',
                'errors' => null,
            ], 403));
        }

        return $me;
    }

    private function resolveFilters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to'   => ['nullable', 'date_format:Y-m-d'],

            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
            'category_id' => ['nullable', 'integer', 'exists:record_categories,id'],

            // Used by providers() endpoint
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'in:asc,desc'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'type' => ['nullable', 'in:wrong,missing,both'],
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

        return [$from, $to, $data];
    }

    private function applyDateFilter($query, string $column, ?Carbon $from, ?Carbon $to)
    {
        if ($from) $query->where($column, '>=', $from);
        if ($to)   $query->where($column, '<=', $to);
        return $query;
    }

    private function filtersPayload(?Carbon $from, ?Carbon $to, array $data): array
    {
        return [
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'default_all_time' => ($from === null && $to === null),
            'provider_id' => $data['provider_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
        ];
    }

    /**
     * GET /api/admin/dashboard/summary
     */
    public function summary(Request $request)
    {
        $this->ensureAdmin();
        [$from, $to, $data] = $this->resolveFilters($request);

        $providerId = $data['provider_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        // Providers
        $providersQ = Provider::query();
        if ($providerId) $providersQ->where('id', (int) $providerId);
        $totalProviders = (int) $providersQ->count();

        // Students base
        $students = User::query()->where('type', 'student');
        if ($providerId) $students->where('provider_id', (int) $providerId);

        $totalStudents = (int) (clone $students)->count();

        // Attempts (activity-based)
        $attempts = StudentRecordAttempt::query()
            ->whereIn('student_id', (clone $students)->select('id'));

        $this->applyDateFilter($attempts, 'created_at', $from, $to);

        if ($categoryId) {
            $attempts->whereHas('assignment', fn($q) => $q->where('category_id', (int) $categoryId));
        }

        $attemptStats = (clone $attempts)
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

        $ftTotal = (int) ($attemptStats->first_try_total ?? 0);
        $ftCorrect = (int) ($attemptStats->first_try_correct ?? 0);
        $ftAccuracy = $ftTotal > 0 ? round(($ftCorrect / $ftTotal) * 100, 2) : 0.0;

        $activeStudents = (int) (clone $attempts)->distinct('student_id')->count('student_id');

        // Assignments (allocation-based)
        $assignments = StudentRecordAssignment::query()
            ->whereIn('student_id', (clone $students)->select('id'));

        $this->applyDateFilter($assignments, 'created_at', $from, $to);

        if ($categoryId) {
            $assignments->where('category_id', (int) $categoryId);
        }

        $assignmentStats = (clone $assignments)
            ->selectRaw("
                count(*) as assigned_total,
                sum(case when status='completed' then 1 else 0 end) as completed,
                sum(case when status='locked' then 1 else 0 end) as locked
            ")
            ->first();

        $assignedTotal = (int) ($assignmentStats->assigned_total ?? 0);
        $completed = (int) ($assignmentStats->completed ?? 0);
        $locked = (int) ($assignmentStats->locked ?? 0);
        $remaining = max(0, $assignedTotal - $completed - $locked);

        $avgAttemptsCompleted = (clone $assignments)
            ->where('status', 'completed')
            ->avg('attempts_used');
        $avgAttemptsCompleted = $avgAttemptsCompleted ? round((float) $avgAttemptsCompleted, 2) : 0.0;

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to, $data),
            'cards' => [
                'total_providers' => $totalProviders,
                'total_students' => $totalStudents,
                'active_students' => $activeStudents,

                'assigned_total' => $assignedTotal,
                'completed' => $completed,
                'remaining' => $remaining,
                'locked' => $locked,

                'total_attempts' => $totalAttempts,
                'accuracy_percent' => $accuracy,
                'first_try_accuracy_percent' => $ftAccuracy,
                'avg_attempts_per_completed_question' => $avgAttemptsCompleted,
            ],
        ], 'Admin dashboard summary');
    }

    /**
     * GET /api/admin/dashboard/growth
     * Uses Provider.created_at and User.created_at (students).
     */
    public function growth(Request $request)
    {
        $this->ensureAdmin();
        [$from, $to, $data] = $this->resolveFilters($request);

        $providerId = $data['provider_id'] ?? null;

        // New students/day
        $newStudents = User::query()->where('type', 'student');
        if ($providerId) $newStudents->where('provider_id', (int) $providerId);
        $this->applyDateFilter($newStudents, 'created_at', $from, $to);

        $studentsSeries = $newStudents
            ->selectRaw("date(created_at) as day, count(*) as new_students")
            ->groupByRaw("date(created_at)")
            ->orderByRaw("date(created_at)")
            ->get();

        // New providers/day
        $providersQ = Provider::query();
        if ($providerId) $providersQ->where('id', (int) $providerId);
        $this->applyDateFilter($providersQ, 'created_at', $from, $to);

        $providersSeries = $providersQ
            ->selectRaw("date(created_at) as day, count(*) as new_providers")
            ->groupByRaw("date(created_at)")
            ->orderByRaw("date(created_at)")
            ->get();

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to, $data),
            'series' => [
                'new_students' => $studentsSeries,
                'new_providers' => $providersSeries,
            ],
        ], 'Growth');
    }

    /**
     * GET /api/admin/dashboard/activity
     * Attempts grouped by day: attempts + correct + active_students
     */
    public function activity(Request $request)
    {
        $this->ensureAdmin();
        [$from, $to, $data] = $this->resolveFilters($request);

        $providerId = $data['provider_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        $students = User::query()->where('type', 'student');
        if ($providerId) $students->where('provider_id', (int) $providerId);

        $attempts = StudentRecordAttempt::query()
            ->whereIn('student_id', (clone $students)->select('id'));

        $this->applyDateFilter($attempts, 'created_at', $from, $to);

        if ($categoryId) {
            $attempts->whereHas('assignment', fn($q) => $q->where('category_id', (int) $categoryId));
        }

        $series = $attempts
            ->selectRaw("
                date(created_at) as day,
                count(*) as attempts,
                sum(case when is_correct=true then 1 else 0 end) as correct,
                count(distinct student_id) as active_students
            ")
            ->groupByRaw("date(created_at)")
            ->orderByRaw("date(created_at)")
            ->get();

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to, $data),
            'series' => $series,
        ], 'Activity');
    }

    /**
     * GET /api/admin/dashboard/categories
     * Category performance across platform.
     */
    public function categories(Request $request)
    {
        $this->ensureAdmin();
        [$from, $to, $data] = $this->resolveFilters($request);

        $providerId = $data['provider_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        $categories = RecordCategory::query()->orderBy('name')->get(['id', 'name', 'slug']);

        $students = User::query()->where('type', 'student');
        if ($providerId) $students->where('provider_id', (int) $providerId);

        // Attempts agg per category (attempts-based for accuracy)
        $attempts = StudentRecordAttempt::query()
            ->whereIn('student_id', (clone $students)->select('id'));

        $this->applyDateFilter($attempts, 'created_at', $from, $to);

        $attemptAgg = $attempts
            ->join('student_record_assignments as a', 'a.id', '=', 'student_record_attempts.assignment_id')
            ->selectRaw("
                a.category_id as category_id,
                count(*) as attempts,
                sum(case when student_record_attempts.is_correct=true then 1 else 0 end) as correct,
                sum(case when student_record_attempts.attempt_no=1 then 1 else 0 end) as first_try_total,
                sum(case when student_record_attempts.attempt_no=1 and student_record_attempts.is_correct=true then 1 else 0 end) as first_try_correct
            ")
            ->when($categoryId, fn($q) => $q->where('a.category_id', (int) $categoryId))
            ->groupBy('a.category_id')
            ->get()
            ->keyBy('category_id');

        // Assignment agg per category (allocation-based)
        $assignments = StudentRecordAssignment::query()
            ->whereIn('student_id', (clone $students)->select('id'));

        $this->applyDateFilter($assignments, 'created_at', $from, $to);

        $assignAgg = $assignments
            ->selectRaw("
                category_id,
                count(*) as assigned_total,
                sum(case when status='completed' then 1 else 0 end) as completed,
                sum(case when status='locked' then 1 else 0 end) as locked
            ")
            ->when($categoryId, fn($q) => $q->where('category_id', (int) $categoryId))
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        $items = $categories->map(function ($cat) use ($attemptAgg, $assignAgg) {
            $a = $attemptAgg->get($cat->id);
            $s = $assignAgg->get($cat->id);

            $attempts = (int) ($a->attempts ?? 0);
            $correct = (int) ($a->correct ?? 0);
            $accuracy = $attempts > 0 ? round(($correct / $attempts) * 100, 2) : 0.0;

            $ftTotal = (int) ($a->first_try_total ?? 0);
            $ftCorrect = (int) ($a->first_try_correct ?? 0);
            $ftAccuracy = $ftTotal > 0 ? round(($ftCorrect / $ftTotal) * 100, 2) : 0.0;

            $assigned = (int) ($s->assigned_total ?? 0);
            $completed = (int) ($s->completed ?? 0);
            $locked = (int) ($s->locked ?? 0);
            $remaining = max(0, $assigned - $completed - $locked);
            $lockedRate = $assigned > 0 ? round(($locked / $assigned) * 100, 2) : 0.0;

            return [
                'category_id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,

                'assigned_total' => $assigned,
                'completed' => $completed,
                'locked' => $locked,
                'remaining' => $remaining,
                'locked_rate_percent' => $lockedRate,

                'attempts' => $attempts,
                'accuracy_percent' => $accuracy,
                'first_try_accuracy_percent' => $ftAccuracy,
            ];
        });

        $items = $categoryId ? $items->where('category_id', (int) $categoryId)->values() : $items->values();

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to, $data),
            'items' => $items,
        ], 'Categories');
    }

    /**
     * GET /api/admin/dashboard/mistakes?type=wrong|missing|both&limit=10
     * Explodes JSON arrays across all students (optionally scoped by provider/category).
     */
    public function mistakes(Request $request)
    {
        $this->ensureAdmin();
        [$from, $to, $data] = $this->resolveFilters($request);

        $providerId = $data['provider_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        $type = $data['type'] ?? 'both';
        $limit = (int) ($data['limit'] ?? 10);

        $bindings = [];
        $where = " u.type = 'student' ";

        if ($providerId) {
            $where .= " AND u.provider_id = :provider_id ";
            $bindings['provider_id'] = (int) $providerId;
        }

        if ($from) {
            $where .= " AND a.created_at >= :from ";
            $bindings['from'] = $from->toDateTimeString();
        }
        if ($to) {
            $where .= " AND a.created_at <= :to ";
            $bindings['to'] = $to->toDateTimeString();
        }

        if ($categoryId) {
            $where .= " AND asg.category_id = :category_id ";
            $bindings['category_id'] = (int) $categoryId;
        }

        $result = [];

        if ($type === 'wrong' || $type === 'both') {
            $sqlWrong = "
                select code, count(*) as count
                from (
                    select jsonb_array_elements_text(a.wrong_codes::jsonb) as code
                    from student_record_attempts a
                    join users u on u.id = a.student_id
                    join student_record_assignments asg on asg.id = a.assignment_id
                    where {$where} and a.wrong_codes is not null
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
                    join student_record_assignments asg on asg.id = a.assignment_id
                    where {$where} and a.missing_codes is not null
                ) t
                group by code
                order by count desc
                limit {$limit}
            ";
            $result['missing_top'] = DB::select($sqlMissing, $bindings);
        }

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to, $data),
            'limit' => $limit,
            'data' => $result,
        ], 'Mistakes');
    }

    /**
     * GET /api/admin/dashboard/funnel
     * Funnel: Students → Assigned ≥1 → Attempted ≥1 → Completed ≥1 → Locked ≥1
     */
    public function funnel(Request $request)
    {
        $this->ensureAdmin();
        [$from, $to, $data] = $this->resolveFilters($request);

        $providerId = $data['provider_id'] ?? null;
        $categoryId = $data['category_id'] ?? null;

        $students = User::query()->where('type', 'student');
        if ($providerId) $students->where('provider_id', (int) $providerId);
        $studentIds = (clone $students)->select('id');

        $assignedQ = StudentRecordAssignment::query()->whereIn('student_id', $studentIds);
        $this->applyDateFilter($assignedQ, 'created_at', $from, $to);
        if ($categoryId) $assignedQ->where('category_id', (int) $categoryId);

        $attemptsQ = StudentRecordAttempt::query()->whereIn('student_id', $studentIds);
        $this->applyDateFilter($attemptsQ, 'created_at', $from, $to);

        $totalStudents = (int) (clone $students)->count();
        $studentsAssigned = (int) (clone $assignedQ)->distinct('student_id')->count('student_id');
        $studentsAttempted = (int) (clone $attemptsQ)->distinct('student_id')->count('student_id');

        $studentsCompleted = (int) (clone $assignedQ)->where('status', 'completed')->distinct('student_id')->count('student_id');
        $studentsLocked = (int) (clone $assignedQ)->where('status', 'locked')->distinct('student_id')->count('student_id');

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to, $data),
            'funnel' => [
                ['stage' => 'total_students', 'count' => $totalStudents],
                ['stage' => 'students_with_assignments', 'count' => $studentsAssigned],
                ['stage' => 'students_with_attempts', 'count' => $studentsAttempted],
                ['stage' => 'students_with_completed', 'count' => $studentsCompleted],
                ['stage' => 'students_with_locked', 'count' => $studentsLocked],
            ],
        ], 'Funnel');
    }

    /**
     * GET /api/admin/dashboard/providers?from=&to=&category_id=&q=&sort=&direction=&page=&per_page=
     * Provider leaderboard using providers table.
     */
    public function providers(Request $request)
    {
        $this->ensureAdmin();
        [$from, $to, $data] = $this->resolveFilters($request);

        $categoryId = $data['category_id'] ?? null;

        $allowedSorts = [
            'id', 'name', 'code',
            'students', 'active_students',
            'attempts', 'accuracy_percent',
            'locked_rate_percent', 'last_attempt_at'
        ];
        $sort = in_array(($data['sort'] ?? ''), $allowedSorts, true) ? $data['sort'] : 'id';
        $direction = $data['direction'] ?? 'asc';

        $providersQ = Provider::query()->select(['id', 'name', 'code', 'created_at']);

        if (!empty($data['provider_id'])) {
            $providersQ->where('id', (int) $data['provider_id']);
        }

        if (!empty($data['q'])) {
            $q = trim($data['q']);
            $providersQ->where(function ($qq) use ($q) {
                $qq->where('name', 'ilike', "%{$q}%")
                   ->orWhere('code', 'ilike', "%{$q}%");
            });
        }

        // Paginate providers first
        $paginator = $this->paginate($providersQ->orderBy('id', 'asc'), $request);
        $providerIds = collect($paginator->items())->pluck('id')->values()->all();

        if (empty($providerIds)) {
            return $this->ok([
                'filters' => $this->filtersPayload($from, $to, $data),
                'data' => $paginator,
            ], 'Providers');
        }

        // Students per provider (all-time)
        $studentsAgg = User::query()
            ->where('type', 'student')
            ->whereIn('provider_id', $providerIds)
            ->selectRaw('provider_id, count(*) as students')
            ->groupBy('provider_id')
            ->get()
            ->keyBy('provider_id');

        // Attempts per provider (time-filtered)
        $attemptsBase = StudentRecordAttempt::query()
            ->join('users as u', 'u.id', '=', 'student_record_attempts.student_id')
            ->whereIn('u.provider_id', $providerIds)
            ->where('u.type', 'student');

        $this->applyDateFilter($attemptsBase, 'student_record_attempts.created_at', $from, $to);

        if ($categoryId) {
            $attemptsBase->join('student_record_assignments as asg', 'asg.id', '=', 'student_record_attempts.assignment_id')
                ->where('asg.category_id', (int) $categoryId);
        }

        $attemptsAgg = (clone $attemptsBase)
            ->selectRaw("
                u.provider_id as provider_id,
                count(*) as attempts,
                sum(case when student_record_attempts.is_correct=true then 1 else 0 end) as correct,
                count(distinct student_record_attempts.student_id) as active_students,
                max(student_record_attempts.created_at) as last_attempt_at
            ")
            ->groupBy('u.provider_id')
            ->get()
            ->keyBy('provider_id');

        // Locked rate per provider (assignment-based; time-filtered on assignments.created_at)
        $assignBase = StudentRecordAssignment::query()
            ->join('users as u', 'u.id', '=', 'student_record_assignments.student_id')
            ->whereIn('u.provider_id', $providerIds)
            ->where('u.type', 'student');

        $this->applyDateFilter($assignBase, 'student_record_assignments.created_at', $from, $to);

        if ($categoryId) {
            $assignBase->where('student_record_assignments.category_id', (int) $categoryId);
        }

        $assignAgg = (clone $assignBase)
            ->selectRaw("
                u.provider_id as provider_id,
                count(*) as assigned_total,
                sum(case when student_record_assignments.status='locked' then 1 else 0 end) as locked
            ")
            ->groupBy('u.provider_id')
            ->get()
            ->keyBy('provider_id');

        // Build items with computed stats
        $items = collect($paginator->items())->map(function ($p) use ($studentsAgg, $attemptsAgg, $assignAgg) {
            $pid = (int) $p['id'];

            $s = $studentsAgg->get($pid);
            $a = $attemptsAgg->get($pid);
            $asg = $assignAgg->get($pid);

            $students = (int) ($s->students ?? 0);
            $attempts = (int) ($a->attempts ?? 0);
            $correct = (int) ($a->correct ?? 0);
            $activeStudents = (int) ($a->active_students ?? 0);

            $accuracy = $attempts > 0 ? round(($correct / $attempts) * 100, 2) : 0.0;

            $assignedTotal = (int) ($asg->assigned_total ?? 0);
            $locked = (int) ($asg->locked ?? 0);
            $lockedRate = $assignedTotal > 0 ? round(($locked / $assignedTotal) * 100, 2) : 0.0;

            return [
                'id' => $pid,
                'name' => $p['name'],
                'code' => $p['code'],
                'created_at' => $p['created_at'] ?? null,

                'students' => $students,
                'active_students' => $activeStudents,
                'attempts' => $attempts,
                'accuracy_percent' => $accuracy,
                'locked_rate_percent' => $lockedRate,
                'last_attempt_at' => $a->last_attempt_at ?? null,
            ];
        });

        // Sort by computed stats on the current page
        if (in_array($sort, ['students', 'active_students', 'attempts', 'accuracy_percent', 'locked_rate_percent', 'last_attempt_at'], true)) {
            $items = $items->sortBy($sort, SORT_REGULAR, $direction === 'desc')->values();
        } else {
            // default sort by provider fields
            $items = $items->sortBy($sort, SORT_REGULAR, $direction === 'desc')->values();
        }

        $paginator->setCollection($items);

        return $this->ok([
            'filters' => $this->filtersPayload($from, $to, $data),
            'data' => $paginator,
        ], 'Providers');
    }
}
