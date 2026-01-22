<?php

namespace App\Console\Commands;

use App\Imports\MedicalRecordsImport; // <-- change if your import class name differs
use App\Models\MedicalRecord;
use App\Models\StudentRecordAssignment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AssignAllQuestionsToStudents extends Command
{
    protected $signature = 'assign:all-questions
        {--provider_id= : Only assign for students within this provider_id}
        {--student_id= : Assign only for this single student_id}
        {--category_id= : Only assign questions in this category_id}
        {--include-inactive : Include inactive medical_records}
        {--max_attempts=3 : Max attempts per assignment}
        {--dry-run : Show what would happen without writing}
        {--chunk=500 : Chunk size for processing}
        {--from-imports : Import XLSX files from storage/app/imports/questions then assign}
    ';

    protected $description = 'Assign all questions (medical_records) to students, skipping duplicates. Optionally import from XLSX folder first.';

    public function handle(): int
    {
        $providerId = $this->option('provider_id') ? (int) $this->option('provider_id') : null;
        $studentId  = $this->option('student_id') ? (int) $this->option('student_id') : null;
        $categoryId = $this->option('category_id') ? (int) $this->option('category_id') : null;

        $includeInactive = (bool) $this->option('include-inactive');
        $maxAttempts = (int) $this->option('max_attempts');
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));
        $fromImports = (bool) $this->option('from-imports');

        if ($maxAttempts < 1 || $maxAttempts > 10) {
            $this->error('max_attempts must be between 1 and 10');
            return self::FAILURE;
        }

        // Validate student/provider combination early
        if ($studentId) {
            $student = User::query()
                ->where('id', $studentId)
                ->where('type', 'student')
                ->first();

            if (!$student) {
                $this->error("Student not found: {$studentId}");
                return self::FAILURE;
            }

            if ($providerId && (int)$student->provider_id !== (int)$providerId) {
                $this->error("student_id {$studentId} does not belong to provider_id {$providerId}");
                return self::FAILURE;
            }
        }

        // Optionally import XLSX files from storage/app/imports/questions
        if ($fromImports) {
            $ok = $this->importFromQuestionsFolder($dryRun);
            if (!$ok) return self::FAILURE;
        }

        // Build question bank query (from DB medical_records)
        $recordsQuery = MedicalRecord::query()->select(['id', 'category_id']);

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

        if ($studentId) {
            $studentsQuery->where('id', $studentId);
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

        $studentsQuery->orderBy('id')->chunkById($chunk, function ($students) use (
            $recordsQuery,
            $maxAttempts,
            $dryRun,
            &$createdTotal,
            &$skippedTotal,
            $bar
        ) {
            foreach ($students as $student) {
                // Pull existing assignments for this student (avoid duplicates)
                $assignedIds = StudentRecordAssignment::query()
                    ->where('student_id', $student->id)
                    ->pluck('medical_record_id')
                    ->all();

                $assignedSet = array_flip($assignedIds);

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

                    if (!$dryRun && !empty($rowsToInsert)) {
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

    private function importFromQuestionsFolder(bool $dryRun): bool
    {
        $dir = storage_path('app/imports/questions');

        if (!File::exists($dir)) {
            $this->error("Questions folder not found: {$dir}");
            $this->line("Create it and place .xlsx files inside: storage/app/imports/questions");
            return false;
        }

        $files = collect(File::files($dir))
            ->filter(fn($f) => strtolower($f->getExtension()) === 'xlsx')
            ->values();

        if ($files->isEmpty()) {
            $this->warn("No .xlsx files found in: {$dir}");
            return true; // not a failure; just nothing to import
        }

        $this->info("Found {$files->count()} XLSX file(s) in imports/questions");

        foreach ($files as $file) {
            $path = $file->getPathname();
            $this->line("Importing: {$path}");
            $sheetNames = IOFactory::load($path)->getSheetNames();

            if ($dryRun) {
                $this->line("DRY RUN: skipping import for {$file->getFilename()}");
                continue;
            }

            try {
                // Import supports multiple sheets via your existing import
                Excel::import(new MedicalRecordsImport($sheetNames, $deactivateMissing ?? false), $path);
            } catch (\Throwable $e) {
                $this->error("Import failed for {$file->getFilename()}: " . $e->getMessage());
                return false;
            }
        }

        $this->info("Imports completed.");
        return true;
    }
}
