<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Concerns\WithPerPagePagination;
use App\Http\Controllers\Controller;
use App\Models\MedicalRecord;
use App\Models\MedicalRecordCode;
use App\Models\RecordCategory;
use App\Models\StudentRecordAssignment;
use App\Models\StudentRecordAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentGameplayController extends Controller
{
    use ApiResponds, WithPerPagePagination;

    /**
     * GET /api/provider/my/categories
     * Returns categories with progress for the logged-in student.
     */
    public function myCategories(Request $request)
    {
        $me = Auth::guard('provider_api')->user();

        if ($me->type !== 'student') {
            return $this->fail('Only students can access this endpoint', null, 403);
        }

        $categories = RecordCategory::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $stats = StudentRecordAssignment::query()
            ->where('student_id', $me->id)
            ->selectRaw('category_id,
                        count(*) as total,
                        sum(case when status = \'completed\' then 1 else 0 end) as completed,
                        sum(case when status = \'locked\' then 1 else 0 end) as locked')
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        $data = $categories->map(function ($cat) use ($stats) {
            $s = $stats->get($cat->id);

            return [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'assigned_total' => (int) ($s->total ?? 0),
                'completed' => (int) ($s->completed ?? 0),
                'locked' => (int) ($s->locked ?? 0),
            ];
        });

        return $this->ok($data, 'My categories');
    }

    /**
     * GET /api/provider/my/assignments?category_id=1&status=assigned
     */
    public function myAssignments(Request $request)
    {
        $me = Auth::guard('provider_api')->user();

        if ($me->type !== 'student') {
            return $this->fail('Only students can access this endpoint', null, 403);
        }

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:record_categories,id'],
            'status' => ['nullable', 'in:assigned,completed,locked'],
        ]);

        $query = StudentRecordAssignment::query()
            ->where('student_id', $me->id)
            ->with([
                'medicalRecord:id,category_id,patient_name,age,gender,chief_complaints,case_description,is_active',
                'category:id,name,slug',
            ])
            ->orderByDesc('id');

        if (!empty($data['category_id'])) {
            $query->where('category_id', (int) $data['category_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $paginator = $this->paginate($query, $request);

        return $this->ok($paginator, 'My assignments');
    }

    /**
     * GET /api/provider/assignments/{assignment}/question
     * Returns ONLY safe fields (no answer key)
     */
    public function question(StudentRecordAssignment $assignment)
    {
        $me = Auth::guard('provider_api')->user();

        if ($me->type !== 'student') {
            return $this->fail('Only students can access this endpoint', null, 403);
        }

        if ((int) $assignment->student_id !== (int) $me->id) {
            return $this->fail('Not found', null, 404);
        }

        $assignment->load([
            'medicalRecord:id,category_id,patient_name,age,gender,chief_complaints,case_description,is_active',
            'category:id,name,slug',
        ]);

        // In case record deactivated later
        if (!$assignment->medicalRecord || !$assignment->medicalRecord->is_active) {
            return $this->fail('This question is not available', null, 410);
        }

        return $this->ok([
            'assignment' => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'attempts_used' => $assignment->attempts_used,
                'max_attempts' => $assignment->max_attempts,
                'attempts_remaining' => max(0, $assignment->max_attempts - $assignment->attempts_used),
                'last_attempt_at' => $assignment->last_attempt_at,
            ],
            'category' => $assignment->category,
            'record' => [
                'id' => $assignment->medicalRecord->id,
                'patient_name' => $assignment->medicalRecord->patient_name,
                'age' => $assignment->medicalRecord->age,
                'gender' => $assignment->medicalRecord->gender,
                'chief_complaints' => $assignment->medicalRecord->chief_complaints,
                'description' => $assignment->medicalRecord->case_description,
            ],
        ], 'Question');
    }

    /**
     * POST /api/provider/assignments/{assignment}/submit
     * Body: { "codes": ["J15.0", "Z86.43"] }
     */
    public function submit(Request $request, StudentRecordAssignment $assignment)
    {
        $me = Auth::guard('provider_api')->user();

        if ($me->type !== 'student') {
            return $this->fail('Only students can submit answers', null, 403);
        }

        if ((int) $assignment->student_id !== (int) $me->id) {
            return $this->fail('Not found', null, 404);
        }

        if ($assignment->status === 'locked') {
            return $this->fail('This question is locked (max attempts used)', null, 423);
        }

        $data = $request->validate([
            'codes' => ['required', 'array', 'min:1'],
            'codes.*' => ['string', 'max:30'],
        ]);

        // Normalize input codes: uppercase, trim, unique
        $submitted = collect($data['codes'])
            ->map(fn($c) => strtoupper(trim($c)))
            ->filter(fn($c) => $c !== '')
            ->unique()
            ->values();

        if ($submitted->isEmpty()) {
            return $this->fail('No valid codes provided', null, 422);
        }

        // Load expected codes
        $expectedRows = MedicalRecordCode::query()
            ->where('medical_record_id', $assignment->medical_record_id)
            ->where('is_required', true)
            ->get(['code', 'comment_wrong', 'comment_missing']);

        $expected = $expectedRows->pluck('code')
            ->map(fn($c) => strtoupper(trim($c)))
            ->unique()
            ->values();

        // Compare sets
        $correct = $submitted->intersect($expected)->values();
        $wrong = $submitted->diff($expected)->values();
        $missing = $expected->diff($submitted)->values();

        $isCorrect = $wrong->isEmpty() && $missing->isEmpty();
        $partialCorrect = !$isCorrect && $correct->isNotEmpty(); // ✅ NEW

        return DB::transaction(function () use ($assignment, $me, $submitted, $correct, $wrong, $missing, $isCorrect, $partialCorrect, $expectedRows, $expected) {

            // increment attempts
            $nextAttemptNo = $assignment->attempts_used + 1;

            // Record attempt row
            StudentRecordAttempt::create([
                'student_id' => $me->id,
                'medical_record_id' => $assignment->medical_record_id,
                'assignment_id' => $assignment->id,
                'attempt_no' => $nextAttemptNo,
                'submitted_codes' => $submitted->all(),
                'is_correct' => $isCorrect,
                'wrong_codes' => $wrong->isEmpty() ? null : $wrong->all(),
                'missing_codes' => $missing->isEmpty() ? null : $missing->all(),
            ]);

            $assignment->attempts_used = $nextAttemptNo;
            $assignment->last_attempt_at = now();

            if ($isCorrect) {
                $assignment->status = 'completed';
            } else {
                if ($assignment->attempts_used >= $assignment->max_attempts) {
                    $assignment->status = 'locked';
                }
            }

            $assignment->save();

            // Build feedback (comments per wrong/missing code)
            $byCode = $expectedRows->keyBy(fn($r) => strtoupper(trim($r->code)));

            $wrongFeedback = $wrong->map(function ($code) {
                return [
                    'code' => $code,
                    'comment' => 'The entered code is not correct for this record.',
                ];
            })->values();

            $missingFeedback = $missing->map(function ($code) use ($byCode) {
                $row = $byCode->get($code);
                return [
                    'code' => $code,
                    'comment' => $row?->comment_missing ?: 'A required code is missing.',
                ];
            })->values();

            // If wrong exists, show the "comment_wrong" for the record’s codes.
            $wrongComment = null;
            if (!$wrong->isEmpty()) {
                $wrongComment = $expectedRows->pluck('comment_wrong')->filter()->first()
                    ?: 'One or more codes are incorrect.';
            }

            return $this->ok([
                'result' => [
                    'is_correct' => $isCorrect,
                    'partial_correct' => $partialCorrect,
                    'status' => $assignment->status,
                    'attempts_used' => $assignment->attempts_used,
                    'max_attempts' => $assignment->max_attempts,
                    'attempts_remaining' => max(0, $assignment->max_attempts - $assignment->attempts_used),
                ],
                'submitted_codes' => $submitted->all(),
                'feedback' => [
                    'correct_codes' => $isCorrect ? $expected->all() : $correct->all(),
                    'wrong_codes' => $wrong->all(),
                    // 'missing_codes' => $missing->all(),
                    'wrong_comment' => $wrongComment,
                    'wrong_details' => $wrongFeedback,
                    'missing_details' => $missingFeedback,
                ],
            ], $isCorrect ? 'Correct' : 'Incorrect');
        });
    }

}
