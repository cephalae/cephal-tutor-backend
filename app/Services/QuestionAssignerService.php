<?php

namespace App\Services;

use App\Models\MedicalRecord;
use App\Models\StudentRecordAssignment;
use Illuminate\Support\Facades\DB;

class QuestionAssignerService
{
    /**
     * Assign questions to a single student, skipping duplicates.
     * Returns [created => int, skipped => int]
     */
    public function assignAllToStudent(
        int $studentId,
        ?int $categoryId = null,
        bool $includeInactive = false,
        int $maxAttempts = 3
    ): array {
        $recordsQ = MedicalRecord::query()->select(['id', 'category_id']);

        if (!$includeInactive) {
            $recordsQ->where('is_active', true);
        }
        if ($categoryId) {
            $recordsQ->where('category_id', $categoryId);
        }

        // existing assignments for this student (so we can compute skipped accurately)
        $existingIds = StudentRecordAssignment::query()
            ->where('student_id', $studentId)
            ->pluck('medical_record_id')
            ->all();

        $existingSet = array_flip($existingIds);

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($recordsQ, $studentId, $maxAttempts, $existingSet, &$created, &$skipped) {
            $now = now();

            $recordsQ->orderBy('id')->chunkById(1000, function ($records) use (
                $studentId,
                $maxAttempts,
                $existingSet,
                $now,
                &$created,
                &$skipped
            ) {
                $rows = [];

                foreach ($records as $r) {
                    if (isset($existingSet[$r->id])) {
                        $skipped++;
                        continue;
                    }

                    $rows[] = [
                        'student_id' => $studentId,
                        'medical_record_id' => $r->id,
                        'category_id' => $r->category_id,
                        'status' => 'assigned',
                        'attempts_used' => 0,
                        'max_attempts' => $maxAttempts,
                        'last_attempt_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (!empty($rows)) {
                    // requires unique(student_id, medical_record_id) to truly "skip duplicates" under concurrency
                    StudentRecordAssignment::query()->insertOrIgnore($rows);
                    $created += count($rows);
                }
            });
        });

        return ['created' => $created, 'skipped' => $skipped];
    }
}
