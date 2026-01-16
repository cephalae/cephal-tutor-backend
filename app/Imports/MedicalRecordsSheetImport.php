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

class MedicalRecordsSheetImport implements ToCollection, WithHeadingRow, WithEvents
{
    private string $categoryName = '';
    private ?MedicalRecord $currentRecord = null;
    private int $currentSort = 1;
    private array $seenRecordUids = [];

    public function __construct(
        private readonly bool $deactivateMissing = false
    ) {}

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
            // fallback safety
            $this->categoryName = 'Uncategorized';
        }

        DB::transaction(function () use ($rows) {

            $category = RecordCategory::firstOrCreate(
                ['slug' => Str::slug($this->categoryName)],
                ['name' => $this->categoryName, 'slug' => Str::slug($this->categoryName)]
            );

            // Track seen UIDs for optional deactivate-missing logic per category
            $this->seenRecordUids = [];

            foreach ($rows as $row) {
                $row = $this->normalizeRow($row);

                // Skip completely empty rows
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $hasPatientName = !empty($row['patient_name']);
                $hasChief = !empty($row['chief_complaints']);
                $hasIcd = !empty($row['icd_10_am_code']);

                // New record starts when patient_name exists OR when chief complaints exist and we have no current
                if ($hasPatientName || ($hasChief && $this->currentRecord === null)) {
                    $this->currentSort = 1;

                    $uid = $this->makeSourceUid($category->id, $row);

                    $this->currentRecord = MedicalRecord::updateOrCreate(
                        ['source_uid' => $uid],
                        [
                            'category_id' => $category->id,
                            'source_uid' => $uid,
                            'patient_name' => $row['patient_name'] ?: null,
                            'age' => $row['age'] !== null ? (int) $row['age'] : null,
                            'gender' => $row['gender'] ?: null,
                            'chief_complaints' => $row['chief_complaints'] ?: null,
                            'case_description' => $row['case_description'] ?: null,
                            'is_active' => true,
                        ]
                    );

                    $this->seenRecordUids[] = $uid;

                    // If the same row also has an ICD code, attach it
                    if ($hasIcd) {
                        $this->upsertCode($this->currentRecord, $row);
                    }

                    continue;
                }

                // Continuation row: patient_name blank but icd exists => additional code for previous record
                if (!$hasPatientName && $hasIcd && $this->currentRecord) {
                    $this->upsertCode($this->currentRecord, $row);
                    continue;
                }

                // If row doesn't match patterns, ignore safely
                // (or log it if you want)
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

    private function makeSourceUid(int $categoryId, array $row): string
    {
        // Stable UID: same file imported again = same UID, no duplicate records
        $payload = implode('|', [
            $categoryId,
            trim((string) ($row['patient_name'] ?? '')),
            trim((string) ($row['age'] ?? '')),
            trim((string) ($row['gender'] ?? '')),
            trim((string) ($row['chief_complaints'] ?? '')),
            trim((string) ($row['case_description'] ?? '')),
        ]);

        return hash('sha256', $payload);
    }

    private function normalizeRow($row): array
    {
        // WithHeadingRow gives $row as array-like with snake_case keys.
        // Normalize/alias expected keys.
        $arr = is_array($row) ? $row : $row->toArray();

        // Common header variants you might have
        $patient = $arr['patient_name'] ?? $arr['patient_name_'] ?? $arr['patient'] ?? null;
        $chief   = $arr['chief_complaints'] ?? $arr['chief_complaints_'] ?? $arr['chief_complaint'] ?? null;

        return [
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
            && empty($row['description']);
    }
}
