<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Models\RecordCategory;
use App\Models\StudentRecordAssignment;
use App\Models\StudentRecordAttempt;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentDashboardController extends Controller
{
    use ApiResponds;

    /**
     * Common: parse optional from/to (Y-m-d). Default: no filter.
     */
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

    private function ensureStudent()
    {
        $me = Auth::guard('provider_api')->user();
        if (!$me || $me->type !== 'student') {
            abort(response()->json([
                'success' => false,
                'message' => 'Only students can access this endpoint',
                'errors'  => null,
            ], 403));
        }
        return $me;
    }

    /**
     * GET /api/provider/my/dashboard/summary?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Note on filtering:
     * - Assignment counts use assignments.created_at within date range (allocation time).
     * - Attempt/accuracy metrics use attempts.created_at within date range (activity time).
     */
    public function summary(Request $request)
    {
        $me = $this->ensureStudent();
        [$from, $to] = $this->resolveDateRange($request);

        // Assignments (allocation-based)
        $assignmentsBase = StudentRecordAssignment::query()->where('student_id', $me->id);
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

        // Attempts (activity-based)
        $attemptsBase = StudentRecordAttempt::query()->where('student_id', $me->id);
        $this->applyDateFilter($attemptsBase, 'created_at', $from, $to);

        $attemptStats = (clone $attemptsBase)
            ->selectRaw("
                count(*) as total_attempts,
                sum(case when is_correct = true then 1 else 0 end) as correct_attempts
            ")
            ->first();

        $totalAttempts = (int) ($attemptStats->total_attempts ?? 0);
        $correctAttempts = (int) ($attemptStats->correct_attempts ?? 0);
        $accuracy = $totalAttempts > 0 ? round(($correctAttempts / $totalAttempts) * 100, 2) : 0.0;

        $firstTry = (clone $attemptsBase)->where('attempt_no', 1);
        $firstTryTotal = (int) $firstTry->count();
        $firstTryCorrect = (int) (clone $firstTry)->where('is_correct', true)->count();
        $firstTryAccuracy = $firstTryTotal > 0 ? round(($firstTryCorrect / $firstTryTotal) * 100, 2) : 0.0;

        // Avg attempts per completed question (within assignments filtered range)
        $avgAttemptsCompleted = (clone $assignmentsBase)
            ->where('status', 'completed')
            ->avg('attempts_used');
        $avgAttemptsCompleted = $avgAttemptsCompleted ? round((float) $avgAttemptsCompleted, 2) : 0.0;

        return $this->ok([
            'filters' => [
                'from' => $from?->toDateString(),
                'to'   => $to?->toDateString(),
                'default_all_time' => ($from === null && $to === null),
            ],
            'cards' => [
                'assigned_total' => $assignedTotal,
                'completed' => $completed,
                'remaining' => $remaining,
                'locked' => $locked,
                'accuracy_percent' => $accuracy,
                'first_try_accuracy_percent' => $firstTryAccuracy,
                'avg_attempts_per_completed_question' => $avgAttemptsCompleted,
                'total_attempts' => $totalAttempts,
            ],
        ], 'Dashboard summary');
    }

    /**
     * GET /api/provider/my/dashboard/category-progress?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Uses assignments.created_at date range (allocation-based).
     */
    public function categoryProgress(Request $request)
    {
        $me = $this->ensureStudent();
        [$from, $to] = $this->resolveDateRange($request);

        $base = StudentRecordAssignment::query()
            ->where('student_id', $me->id);

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

        $data = $categories->map(function ($cat) use ($stats) {
            $s = $stats->get($cat->id);
            $assigned = (int) ($s->assigned_total ?? 0);
            $completed = (int) ($s->completed ?? 0);
            $locked = (int) ($s->locked ?? 0);
            $remaining = max(0, $assigned - $completed - $locked);

            return [
                'category_id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'assigned_total' => $assigned,
                'completed' => $completed,
                'locked' => $locked,
                'remaining' => $remaining,
            ];
        });

        return $this->ok([
            'filters' => [
                'from' => $from?->toDateString(),
                'to'   => $to?->toDateString(),
                'default_all_time' => ($from === null && $to === null),
            ],
            'items' => $data,
        ], 'Category progress');
    }

    /**
     * GET /api/provider/my/dashboard/activity?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Activity is attempts-based and grouped by day.
     */
    public function activity(Request $request)
    {
        $me = $this->ensureStudent();
        [$from, $to] = $this->resolveDateRange($request);

        $q = StudentRecordAttempt::query()
            ->where('student_id', $me->id);

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
            'filters' => [
                'from' => $from?->toDateString(),
                'to'   => $to?->toDateString(),
                'default_all_time' => ($from === null && $to === null),
            ],
            'series' => $rows,
        ], 'Activity');
    }

    /**
     * GET /api/provider/my/dashboard/mistakes?from=YYYY-MM-DD&to=YYYY-MM-DD&type=wrong|missing|both&limit=10
     * Uses attempts.created_at date range.
     * Postgres JSONB explode to compute top codes.
     */
    public function mistakes(Request $request)
    {
        $me = $this->ensureStudent();
        [$from, $to] = $this->resolveDateRange($request);

        $data = $request->validate([
            'type' => ['nullable', 'in:wrong,missing,both'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $type = $data['type'] ?? 'both';
        $limit = (int) ($data['limit'] ?? 10);

        $bindings = ['student_id' => $me->id];
        $dateSql = " student_id = :student_id ";

        if ($from) {
            $dateSql .= " AND created_at >= :from ";
            $bindings['from'] = $from->toDateTimeString();
        }
        if ($to) {
            $dateSql .= " AND created_at <= :to ";
            $bindings['to'] = $to->toDateTimeString();
        }

        $result = [];

        if ($type === 'wrong' || $type === 'both') {
            $sqlWrong = "
                select code, count(*) as count
                from (
                    select jsonb_array_elements_text(wrong_codes::jsonb) as code
                    from student_record_attempts
                    where {$dateSql} and wrong_codes is not null
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
                    select jsonb_array_elements_text(missing_codes::jsonb) as code
                    from student_record_attempts
                    where {$dateSql} and missing_codes is not null
                ) t
                group by code
                order by count desc
                limit {$limit}
            ";
            $result['missing_top'] = DB::select($sqlMissing, $bindings);
        }

        return $this->ok([
            'filters' => [
                'from' => $from?->toDateString(),
                'to'   => $to?->toDateString(),
                'default_all_time' => ($from === null && $to === null),
            ],
            'limit' => $limit,
            'data' => $result,
        ], 'Mistakes');
    }

    /**
     * GET /api/provider/my/dashboard/recent-attempts?from=YYYY-MM-DD&to=YYYY-MM-DD&limit=10
     * Uses attempts.created_at date range.
     */
    public function recentAttempts(Request $request)
    {
        $me = $this->ensureStudent();
        [$from, $to] = $this->resolveDateRange($request);

        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $limit = (int) ($data['limit'] ?? 10);

        $q = StudentRecordAttempt::query()
            ->where('student_id', $me->id)
            ->with([
                'assignment:id,status,attempts_used,max_attempts,medical_record_id,category_id',
                'medicalRecord:id,patient_name,category_id',
                'medicalRecord.category:id,name,slug',
            ])
            ->orderByDesc('id');

        $this->applyDateFilter($q, 'created_at', $from, $to);

        $rows = $q->limit($limit)->get()->map(function ($a) {
            return [
                'attempt_id' => $a->id,
                'attempt_no' => $a->attempt_no,
                'is_correct' => (bool) $a->is_correct,
                'submitted_codes' => $a->submitted_codes,
                'wrong_codes' => $a->wrong_codes,
                'missing_codes' => $a->missing_codes,
                'attempted_at' => $a->created_at,
                'assignment' => [
                    'id' => $a->assignment?->id,
                    'status' => $a->assignment?->status,
                    'attempts_used' => $a->assignment?->attempts_used,
                    'max_attempts' => $a->assignment?->max_attempts,
                ],
                'record' => [
                    'id' => $a->medicalRecord?->id,
                    'patient_name' => $a->medicalRecord?->patient_name,
                ],
                'category' => $a->medicalRecord?->category ? [
                    'id' => $a->medicalRecord->category->id,
                    'name' => $a->medicalRecord->category->name,
                    'slug' => $a->medicalRecord->category->slug,
                ] : null,
            ];
        });

        return $this->ok([
            'filters' => [
                'from' => $from?->toDateString(),
                'to'   => $to?->toDateString(),
                'default_all_time' => ($from === null && $to === null),
            ],
            'limit' => $limit,
            'items' => $rows,
        ], 'Recent attempts');
    }
}