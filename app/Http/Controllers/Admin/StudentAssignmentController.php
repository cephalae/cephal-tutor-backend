<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\RecordCategory;
use App\Models\StudentCategorySetting;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentAssignmentController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AssignmentService $service)
    {
    }

    /**
     * PUT /api/admin/students/{student}/category-settings
     * Body: { "settings": [ {"category_id": 1, "questions_count": 10}, ... ] }
     */
    public function upsertCategorySettings(Request $request, User $student)
    {
        if ($student->type !== 'student') {
            return $this->fail('Target user must be a student', null, 422);
        }

        $data = $request->validate([
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.category_id' => ['required', 'integer', 'exists:record_categories,id'],
            'settings.*.questions_count' => ['required', 'integer', 'min:0', 'max:500'],
        ]);

        DB::transaction(function () use ($student, $data) {
            foreach ($data['settings'] as $item) {
                StudentCategorySetting::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'category_id' => (int) $item['category_id'],
                    ],
                    [
                        'questions_count' => (int) $item['questions_count'],
                    ]
                );
            }
        });

        $settings = StudentCategorySetting::query()
            ->where('student_id', $student->id)
            ->with('category:id,name,slug')
            ->get();

        return $this->ok([
            'student_id' => $student->id,
            'settings' => $settings,
        ], 'Category settings saved');
    }

    /**
     * POST /api/admin/students/{student}/generate-assignments
     */
    public function generateAssignments(User $student)
    {
        if ($student->type !== 'student') {
            return $this->fail('Target user must be a student', null, 422);
        }

        $result = $this->service->generateForStudent($student);

        return $this->ok($result, 'Assignments generated');
    }
}
