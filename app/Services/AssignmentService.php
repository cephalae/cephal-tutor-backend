<?php

namespace App\Services;

use App\Models\MedicalRecord;
use App\Models\RecordCategory;
use App\Models\StudentCategorySetting;
use App\Models\StudentRecordAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    /**
     * Generate (top-up) assignments for a student across categories based on student_category_settings.
     * - Adds missing assignments up to desired count.
     * - Never removes existing assignments.
     */
    public function generateForStudent(User $student): array
    {
        return DB::transaction(function () use ($student) {

            $settings = StudentCategorySetting::query()
                ->where('student_id', $student->id)
                ->with('category:id,name,slug')
                ->get();

            $created = 0;
            $perCategory = [];

            foreach ($settings as $setting) {
                $categoryId = $setting->category_id;
                $desired = (int) $setting->questions_count;

                if ($desired <= 0) {
                    $perCategory[$categoryId] = [
                        'desired' => $desired,
                        'existing' => 0,
                        'added' => 0,
                    ];
                    continue;
                }

                $existingCount = StudentRecordAssignment::query()
                    ->where('student_id', $student->id)
                    ->where('category_id', $categoryId)
                    ->count();

                $need = max(0, $desired - $existingCount);

                if ($need === 0) {
                    $perCategory[$categoryId] = [
                        'desired' => $desired,
                        'existing' => $existingCount,
                        'added' => 0,
                    ];
                    continue;
                }

                // Avoid duplicates: exclude already assigned record ids
                $assignedRecordIds = StudentRecordAssignment::query()
                    ->where('student_id', $student->id)
                    ->where('category_id', $categoryId)
                    ->pluck('medical_record_id');

                // Random selection from active question bank
                $records = MedicalRecord::query()
                    ->where('category_id', $categoryId)
                    ->where('is_active', true)
                    ->whereNotIn('id', $assignedRecordIds)
                    ->inRandomOrder()
                    ->limit($need)
                    ->get(['id', 'category_id']);

                $added = 0;

                foreach ($records as $record) {
                    StudentRecordAssignment::firstOrCreate(
                        [
                            'student_id' => $student->id,
                            'medical_record_id' => $record->id,
                        ],
                        [
                            'category_id' => $record->category_id,
                            'status' => 'assigned',
                            'attempts_used' => 0,
                            'max_attempts' => 3,
                        ]
                    );
                    $added++;
                }

                $created += $added;

                $perCategory[$categoryId] = [
                    'desired' => $desired,
                    'existing' => $existingCount,
                    'added' => $added,
                ];
            }

            return [
                'student_id' => $student->id,
                'created' => $created,
                'by_category' => $perCategory,
            ];
        });
    }

    /**
     * Helper: initialize defaults for a student for all categories (optional).
     * If you want per-student settings always present.
     */
    public function ensureSettingsForAllCategories(User $student, int $defaultCount = 0): void
    {
        $categories = RecordCategory::query()->get(['id']);

        foreach ($categories as $category) {
            StudentCategorySetting::firstOrCreate(
                ['student_id' => $student->id, 'category_id' => $category->id],
                ['questions_count' => $defaultCount]
            );
        }
    }
}
