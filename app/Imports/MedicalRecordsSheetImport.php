<?php

namespace App\Imports;

use App\Models\MedicalRecord;
use App\Models\MedicalRecordCode;
use App\Models\RecordCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Events\BeforeSheet;
use Faker\Factory as FakerFactory;
use Faker\Generator as Faker;

class MedicalRecordsSheetImport implements ToCollection, WithHeadingRow, WithEvents
{
    private string $categoryName = '';
    private ?MedicalRecord $currentRecord = null;
    private int $currentSort = 1;
    private array $seenRecordUids = [];
    private Faker $faker;

    public function __construct(
        private readonly bool $deactivateMissing = false
    ) {
        $this->faker = FakerFactory::create('en_US');
    }

    public function registerEvents(): array
    {
        return [
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->categoryName = (string) $event->sheet->getTitle();
            },
        ];
    }

    public function collection(Collection $rows)
    {
        if ($this->categoryName === '') {
            $this->categoryName = 'Uncategorized';
        }

        DB::transaction(function () use ($rows) {

            $category = RecordCategory::firstOrCreate(
                ['slug' => Str::slug($this->categoryName)],
                ['name' => $this->categoryName, 'slug' => Str::slug($this->categoryName)]
            );

            $this->seenRecordUids = [];
            $this->currentRecord = null;

            foreach ($rows as $row) {
                $row = $this->normalizeRow($row);

                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $hasPatientName = !empty($row['patient_name']);
                $hasChief = !empty($row['chief_complaints']);
                $hasIcd = !empty($row['icd_10_am_code']);

                // Start new record
                if ($hasPatientName || ($hasChief && $this->currentRecord === null)) {
                    $this->currentSort = 1;

                    $difficulty = $this->parseDifficultyLevel($row['difficulty_level']) ?? 1;

                    $uid = $this->makeSourceUid($category->id, $row, $difficulty);

                    $fakerName = $this->makeFakerPatientName($row['gender']);

                    $this->currentRecord = MedicalRecord::updateOrCreate(
                        ['source_uid' => $uid],
                        [
                            'category_id' => $category->id,
                            'source_uid' => $uid,
                            'patient_name' => $fakerName,
                            'age' => $row['age'] !== null ? (int) $row['age'] : null,
                            'gender' => $row['gender'] ?: null,
                            'chief_complaints' => $row['chief_complaints'] ?: null,
                            'case_description' => $row['case_description'] ?: null,
                            'difficulty_level' => $difficulty,
                            'is_active' => true,
                        ]
                    );

                    $this->seenRecordUids[] = $uid;

                    if ($hasIcd) {
                        $this->upsertCode($this->currentRecord, $row);
                    }

                    continue;
                }

                // Continuation row => additional code for previous record
                if (!$hasPatientName && $hasIcd && $this->currentRecord) {
                    $this->upsertCode($this->currentRecord, $row);
                    continue;
                }
            }

            if ($this->deactivateMissing) {
                MedicalRecord::query()
                    ->where('category_id', $category->id)
                    ->whereNotIn('source_uid', $this->seenRecordUids)
                    ->update(['is_active' => false]);
            }
        });
    }

    private function upsertCode(MedicalRecord $record, array $row): void
    {
        $code = strtoupper(trim((string) $row['icd_10_am_code']));
        if ($code === '') return;

        MedicalRecordCode::updateOrCreate(
            [
                'medical_record_id' => $record->id,
                'code' => $code,
            ],
            [
                'description' => $row['description'] ?: null,
                'comment_wrong' => $row['comment_for_wrong'] ?: null,
                'comment_missing' => $row['comment_for_missing'] ?: null,
                'is_required' => true,
                'sort_order' => $this->currentSort++,
            ]
        );
    }

    private function makeFakerPatientName(?string $gender): string
    {
        $g = strtoupper(trim((string) $gender));

        if (in_array($g, ['M', 'MALE'], true)) {
            return $this->faker->firstNameMale() . ' ' . $this->faker->lastName();
        }

        if (in_array($g, ['F', 'FEMALE'], true)) {
            return $this->faker->firstNameFemale() . ' ' . $this->faker->lastName();
        }

        return $this->faker->firstName() . ' ' . $this->faker->lastName();
    }

    private function makeSourceUid(int $categoryId, array $row, int $difficulty): string
    {
        $payload = implode('|', [
            $categoryId,
            $difficulty,
            trim((string) ($row['patient_name'] ?? '')),
            trim((string) ($row['age'] ?? '')),
            trim((string) ($row['gender'] ?? '')),
            trim((string) ($row['chief_complaints'] ?? '')),
            trim((string) ($row['case_description'] ?? '')),
        ]);

        return hash('sha256', $payload);
    }

    private function parseDifficultyLevel(?string $value): ?int
    {
        if ($value === null) return null;

        $v = strtolower(trim($value));
        // supports: "Level 1", "lvl 2", "3"
        if (preg_match('/\b([123])\b/', $v, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function normalizeRow($row): array
    {
        $arr = is_array($row) ? $row : $row->toArray();

        $patient = $arr['patient_name'] ?? $arr['patient'] ?? null;
        $chief   = $arr['chief_complaints'] ?? $arr['chief_complaint'] ?? null;

        return [
            // NEW
            'difficulty_level' => $this->clean($arr['levels'] ?? $arr['level'] ?? $arr['difficulty_level'] ?? null),

            'patient_name' => $this->clean($patient),
            'age' => $this->clean($arr['age'] ?? null),
            'gender' => $this->clean($arr['gender'] ?? null),

            'chief_complaints' => $this->clean($chief),
            'case_description' => $this->clean($arr['case_description'] ?? null),

            'icd_10_am_code' => $this->clean($arr['icd_10_am_code'] ?? $arr['icd_10_am'] ?? $arr['icd10am_code'] ?? null),
            'description' => $this->clean($arr['description'] ?? null),
            'comment_for_wrong' => $this->clean($arr['comment_for_wrong'] ?? null),
            'comment_for_missing' => $this->clean($arr['comment_for_missing'] ?? null),
        ];
    }

    private function clean($value): ?string
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    private function isEmptyRow(array $row): bool
    {
        return empty($row['patient_name'])
            && empty($row['chief_complaints'])
            && empty($row['icd_10_am_code'])
            && empty($row['description'])
            && empty($row['difficulty_level']);
    }
}
