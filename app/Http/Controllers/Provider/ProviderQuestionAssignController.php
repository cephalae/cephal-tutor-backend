<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ApiResponds;
use App\Models\User;
use App\Services\QuestionAssignerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProviderQuestionAssignController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly QuestionAssignerService $assigner) {}

    public function assignAll(Request $request, User $student)
    {
        $me = Auth::guard('provider_api')->user();

        if (!$me || !$me->provider_id) {
            return $this->fail('Provider context missing', null, 403);
        }

        // Only provider_admin should be able to do this
        if ($me->type !== 'provider_admin') {
            return $this->fail('Only provider admins can assign questions', null, 403);
        }

        if ($student->type !== 'student') {
            return $this->fail('Target user is not a student', null, 422);
        }

        if ((int) $student->provider_id !== (int) $me->provider_id) {
            return $this->fail('You can only manage students within your provider', null, 403);
        }

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'exists:record_categories,id'],
            'include_inactive' => ['nullable', 'boolean'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $result = $this->assigner->assignAllToStudent(
            studentId: (int) $student->id,
            categoryId: $data['category_id'] ?? null,
            includeInactive: (bool) ($data['include_inactive'] ?? false),
            maxAttempts: (int) ($data['max_attempts'] ?? 3)
        );

        return $this->ok([
            'student_id' => $student->id,
            'provider_id' => $student->provider_id,
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ], 'Questions assigned');
    }
}
