<?php

namespace App\Console\Commands;

use App\Models\MedicalRecord;
use App\Models\StudentRecordAssignment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignAllQuestionsToStudents extends Command
{
    protected $signature = 'assign:all-questions
        {--provider_id= : Only assign for students within this provider_id}
        {--category_id= : Only assign questions in this category_id}
        {--include-inactive : Include inactive medical_records}
        {--max_attempts=3 : Max attempts per assignment}
        {--dry-run : Show what would happen without writing}
        {--chunk=500 : Chunk size for processing}
    ';

    protected $description = 'Assign all questions (medical_records) to all students, skipping duplicates.';

    public function handle(): int
    {
        $providerId = $this->option('provider_id') ? (int) $this->option('provider_id') : null;
        $categoryId = $this->option('category_id') ? (int) $this->option('category_id') : null;
        $includeInactive = (bool) $this->option('include-inactive');
        $maxAttempts = (int) $this->option('max_attempts');
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        if ($maxAttempts < 1 || $maxAttempts > 10) {
            $this->error('max_attempts must be between 1 and 10');
            return self::FAILURE;
        }

        // Build question bank query
        $recordsQuery = MedicalRecord::query()
            ->select(['id', 'category_id']);

        if (!$includeInactive) {
            $recordsQuery->where('is_active', true);
        }

        if ($categoryId) {
            $recordsQuery->where('category_id', $categoryId);
        }

        $totalRecords = (clone $recordsQuery)->count();

        if ($totalRecords === 0) {
            $this->warn('No medical records found for the given filters.');
            return self::SUCCESS;
        }

        // Build students query
        $studentsQuery = User::query()
            ->where('type', 'student')
            ->select(['id', 'provider_id']);

        if ($providerId) {
            $studentsQuery->where('provider_id', $providerId);
        }

        $totalStudents = (clone $studentsQuery)->count();

        if ($totalStudents === 0) {
            $this->warn('No students found for the given filters.');
            return self::SUCCESS;
        }

        $this->info("Students: {$totalStudents}");
        $this->info("Records: {$totalRecords}");
        $this->info($dryRun ? 'DRY RUN enabled (no writes)' : 'Writing assignments...');

        $createdTotal = 0;
        $skippedTotal = 0;

        $bar = $this->output->createProgressBar($totalStudents);
        $bar->start();

        // Chunk students
        $studentsQuery->orderBy('id')->chunkById($chunk, function ($students) use (
            $recordsQuery,
            $maxAttempts,
            $dryRun,
            &$createdTotal,
            &$skippedTotal,
            $bar
        ) {
            foreach ($students as $student) {
                // For performance: prefetch already assigned record ids for this student (filtered by same record query)
                $assignedIds = StudentRecordAssignment::query()
                    ->where('student_id', $student->id)
                    ->pluck('medical_record_id')
                    ->all();

                $assignedSet = array_flip($assignedIds);

                // Iterate all records in chunks too
                (clone $recordsQuery)->orderBy('id')->chunkById(1000, function ($records) use (
                    $student,
                    $maxAttempts,
                    $dryRun,
                    &$createdTotal,
                    &$skippedTotal,
                    $assignedSet
                ) {
                    $rowsToInsert = [];

                    foreach ($records as $record) {
                        if (isset($assignedSet[$record->id])) {
                            $skippedTotal++;
                            continue;
                        }

                        if ($dryRun) {
                            $createdTotal++;
                            continue;
                        }

                        $rowsToInsert[] = [
                            'student_id' => $student->id,
                            'medical_record_id' => $record->id,
                            'category_id' => $record->category_id,
                            'status' => 'assigned',
                            'attempts_used' => 0,
                            'max_attempts' => $maxAttempts,
                            'last_attempt_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }

                    // Bulk insert, ignore duplicates safely (in case of race)
                    if (!$dryRun && !empty($rowsToInsert)) {
                        // Postgres supports insertOrIgnore via Laravel
                        StudentRecordAssignment::query()->insertOrIgnore($rowsToInsert);
                        $createdTotal += count($rowsToInsert);
                    }
                });
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Done.");
        $this->info("Created: {$createdTotal}");
        $this->info("Skipped (already existed): {$skippedTotal}");

        return self::SUCCESS;
    }
}
