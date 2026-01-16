<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\StudentCategorySetting;
use App\Models\User;
use App\Services\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProviderStudentAssignmentController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly AssignmentService $service)
    {
    }

    public function upsertCategorySettings(Request $request, User $student)
    {
        $me = Auth::guard('provider_api')->user();

        if ($student->type !== 'student') {
            return $this->fail('Target user must be a student', null, 422);
        }

        // Scope: must be same provider
        if ((int) $student->provider_id !== (int) $me->provider_id) {
            return $this->fail('Forbidden', null, 403);
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

    public function generateAssignments(User $student)
    {
        $me = Auth::guard('provider_api')->user();

        if ($student->type !== 'student') {
            return $this->fail('Target user must be a student', null, 422);
        }

        if ((int) $student->provider_id !== (int) $me->provider_id) {
            return $this->fail('Forbidden', null, 403);
        }

        $result = $this->service->generateForStudent($student);

        return $this->ok($result, 'Assignments generated');
    }
}
