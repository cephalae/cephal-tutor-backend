<?php

namespace App\Console\Commands;

use App\Models\DiagnosisCategory;
use App\Models\DiagnosisCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportDiagnosisCodes extends Command
{
    protected $signature = 'diagnosis:import
        {--categories=storage/app/imports/codes/category.csv}
        {--codes=storage/app/imports/codes/diagnosis-code.csv}';

    protected $description = 'Import diagnosis categories and diagnosis codes from semicolon-delimited CSV files.';

    public function handle(): int
    {
        $categoriesPath = base_path($this->option('categories'));
        $codesPath      = base_path($this->option('codes'));

        if (!file_exists($categoriesPath)) {
            $this->error("Categories CSV not found: {$categoriesPath}");
            return self::FAILURE;
        }
        if (!file_exists($codesPath)) {
            $this->error("Diagnosis codes CSV not found: {$codesPath}");
            return self::FAILURE;
        }

        DB::beginTransaction();

        try {
            $this->info('Importing categories...');
            $this->importCategories($categoriesPath);

            $this->info('Computing category depth/path/path_label...');
            $this->computeCategoryTreeHelpers();

            $this->info('Importing diagnosis codes...');
            $this->importDiagnosisCodes($codesPath);

            $this->info('Resetting sequences (Postgres) if needed...');
            $this->resetPgSequences();

            DB::commit();

            $this->info('Done ✅');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import failed: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
    }

    private function importCategories(string $path): void
    {
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(';', '"', "\\");

        $header = null;
        $batch = [];
        $batchSize = 1000;

        foreach ($file as $row) {
            if (!$row || count($row) < 2) continue;

            // header row
            if ($header === null) {
                $header = $row;
                continue;
            }

            $data = $this->mapRow($header, $row);

            // CSV: parent_id "0" means root
            $parentId = (int)($data['parent_id'] ?? 0);
            $parentId = $parentId === 0 ? null : $parentId;

            $status = trim((string)($data['status'] ?? 'Active'));
            $isActive = Str::lower($status) === 'active';

            $createdOn = $data['created_on'] ?? null;
            $modifiedOn = $data['modified_on'] ?? null;

            $batch[] = [
                'id' => (int)$data['id'],
                'parent_id' => $parentId,
                'category_name' => (string)($data['category_name'] ?? ''),
                'description' => $data['description'] ?? null,
                'keyword' => $data['keyword'] ?? null,
                'is_active' => $isActive,
                'sort_order' => $data['sort_order'] !== null && $data['sort_order'] !== '' ? (int)$data['sort_order'] : null,

                'created_by' => $data['created_by'] !== null && $data['created_by'] !== '' ? (int)$data['created_by'] : null,
                'created_ip' => $data['created_ip'] ?? null,
                'modified_by' => $data['modified_by'] !== null && $data['modified_by'] !== '' ? (int)$data['modified_by'] : null,
                'modified_ip' => $data['modified_ip'] ?? null,

                // Map CSV dates to laravel timestamps
                'created_at' => $createdOn ?: now(),
                'updated_at' => $modifiedOn ?: ($createdOn ?: now()),
            ];

            if (count($batch) >= $batchSize) {
                DiagnosisCategory::upsert(
                    $batch,
                    ['id'],
                    [
                        'parent_id','category_name','description','keyword','is_active','sort_order',
                        'created_by','created_ip','modified_by','modified_ip',
                        'created_at','updated_at'
                    ]
                );
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DiagnosisCategory::upsert(
                $batch,
                ['id'],
                [
                    'parent_id','category_name','description','keyword','is_active','sort_order',
                    'created_by','created_ip','modified_by','modified_ip',
                    'created_at','updated_at'
                ]
            );
        }
    }

    private function computeCategoryTreeHelpers(): void
    {
        $rows = DiagnosisCategory::query()
            ->select(['id', 'parent_id', 'category_name'])
            ->get();

        $parentMap = [];
        $nameMap = [];

        foreach ($rows as $r) {
            $parentMap[(int)$r->id] = $r->parent_id ? (int)$r->parent_id : null;
            $nameMap[(int)$r->id] = (string)$r->category_name;
        }

        $memo = [];
        $visiting = [];

        $compute = function (int $id) use (&$compute, &$memo, &$visiting, $parentMap, $nameMap) {
            if (isset($memo[$id])) return $memo[$id];
            if (isset($visiting[$id])) {
                // cycle protection
                $name = $nameMap[$id] ?? (string)$id;
                return $memo[$id] = [
                    'depth' => 0,
                    'path' => (string)$id,
                    'path_label' => $name,
                ];
            }

            $visiting[$id] = true;

            $name = $nameMap[$id] ?? (string)$id;
            $parent = $parentMap[$id] ?? null;

            if ($parent === null) {
                $res = [
                    'depth' => 0,
                    'path' => (string)$id,
                    'path_label' => $name,
                ];
            } else {
                $p = $compute($parent);
                $res = [
                    'depth' => (int)$p['depth'] + 1,
                    'path' => $p['path'] . '/' . $id,
                    'path_label' => $p['path_label'] . ' > ' . $name,
                ];
            }

            unset($visiting[$id]);
            return $memo[$id] = $res;
        };

        $updates = [];
        $batchSize = 1000;

        foreach (array_keys($parentMap) as $id) {
            $id = (int)$id;
            $info = $compute($id);

            // IMPORTANT: include category_name so Postgres UPSERT doesn't violate NOT NULL
            $updates[] = [
                'id' => $id,
                'category_name' => $nameMap[$id] ?? (string)$id,
                'depth' => (int)$info['depth'],
                'path' => $info['path'],
                'path_label' => $info['path_label'],
                'updated_at' => now(),
            ];

            if (count($updates) >= $batchSize) {
                DiagnosisCategory::upsert(
                    $updates,
                    ['id'],
                    ['category_name', 'depth', 'path', 'path_label', 'updated_at']
                );
                $updates = [];
            }
        }

        if (!empty($updates)) {
            DiagnosisCategory::upsert(
                $updates,
                ['id'],
                ['category_name', 'depth', 'path', 'path_label', 'updated_at']
            );
        }
    }


    private function importDiagnosisCodes(string $path): void
    {
        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(';', '"', "\\");

        $header = null;

        // Load existing category IDs into a set (fast FK check)
        $knownCategoryIds = array_fill_keys(
            DiagnosisCategory::query()->pluck('id')->all(),
            true
        );

        // Missing categories to be created (id => row)
        $missingCats = [];
        $missingCreated = 0;

        $flushMissing = function () use (&$missingCats, &$knownCategoryIds, &$missingCreated) {
            if (empty($missingCats)) return;

            DiagnosisCategory::upsert(
                array_values($missingCats),
                ['id'],
                [
                    'parent_id', 'category_name', 'description', 'keyword',
                    'is_active', 'sort_order', 'depth', 'path', 'path_label',
                    'updated_at'
                ]
            );

            $missingCreated += count($missingCats);
            $missingCats = [];
        };

        // Dedupe codes inside batch using "category_id|CODE"
        $batch = [];
        $batchSize = 2000;

        $flushCodes = function () use (&$batch) {
            if (empty($batch)) return;

            // Upsert by the DB unique key
            DiagnosisCode::upsert(
                array_values($batch),
                ['category_id', 'code'],
                ['long_description', 'short_description', 'updated_at']
            );

            $batch = [];
        };

        foreach ($file as $row) {
            if (!$row || count($row) < 2) continue;

            if ($header === null) {
                $header = $row;
                continue;
            }

            $data = $this->mapRow($header, $row);

            $categoryId = (int)($data['cat_id'] ?? 0);
            $code = strtoupper(trim((string)($data['code'] ?? '')));

            if ($categoryId <= 0 || $code === '') {
                continue;
            }

            // Ensure FK exists: create placeholder category if missing
            if (!isset($knownCategoryIds[$categoryId])) {
                $knownCategoryIds[$categoryId] = true;

                $name = "MISSING_CAT_{$categoryId}";
                $missingCats[$categoryId] = [
                    'id' => $categoryId,
                    'parent_id' => null,
                    'category_name' => $name,
                    'description' => 'Placeholder category auto-created during diagnosis code import because cat_id was missing from category.csv.',
                    'keyword' => null,
                    'is_active' => true,
                    'sort_order' => null,
                    'depth' => 0,
                    'path' => (string)$categoryId,
                    'path_label' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // flush placeholders in chunks
                if (count($missingCats) >= 500) {
                    $flushMissing();
                }
            }

            $key = $categoryId . '|' . $code;

            $long = $data['long_description'] ?? null;
            $short = $data['short_description'] ?? null;

            if (!isset($batch[$key])) {
                // NOTE: we do NOT import CSV 'id' for diagnosis_codes anymore (safer)
                $batch[$key] = [
                    'category_id' => $categoryId,
                    'code' => $code,
                    'long_description' => $long,
                    'short_description' => $short,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            } else {
                // Merge duplicates: keep better descriptions
                $existingLong = (string)($batch[$key]['long_description'] ?? '');
                $newLong = (string)($long ?? '');
                if ($newLong !== '' && ($existingLong === '' || strlen($newLong) > strlen($existingLong))) {
                    $batch[$key]['long_description'] = $newLong;
                }

                $existingShort = (string)($batch[$key]['short_description'] ?? '');
                $newShort = (string)($short ?? '');
                if ($newShort !== '' && ($existingShort === '' || strlen($newShort) > strlen($existingShort))) {
                    $batch[$key]['short_description'] = $newShort;
                }

                $batch[$key]['updated_at'] = now();
            }

            if (count($batch) >= $batchSize) {
                // IMPORTANT: ensure missing categories exist before inserting codes
                $flushMissing();
                $flushCodes();
            }
        }

        // final flush
        $flushMissing();
        $flushCodes();

        if ($missingCreated > 0) {
            $this->warn("Created {$missingCreated} placeholder categories because some cat_id values were missing in category.csv.");
        }
    }



    private function resetPgSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') return;

        // ensure sequences follow max(id)
        DB::statement("SELECT setval(pg_get_serial_sequence('diagnosis_categories','id'), COALESCE(MAX(id), 1)) FROM diagnosis_categories;");
        DB::statement("SELECT setval(pg_get_serial_sequence('diagnosis_codes','id'), COALESCE(MAX(id), 1)) FROM diagnosis_codes;");
    }

    private function mapRow(array $header, array $row): array
    {
        $out = [];
        foreach ($header as $i => $key) {
            if ($key === null) continue;

            $k = trim((string)$key, "\" \t\n\r\0\x0B");
            // strip UTF-8 BOM if present
            $k = preg_replace('/^\xEF\xBB\xBF/', '', $k);

            $out[$k] = isset($row[$i]) ? trim((string)$row[$i], "\" \t\n\r\0\x0B") : null;
        }
        return $out;
    }

}
