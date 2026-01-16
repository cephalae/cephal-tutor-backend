<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Imports\MedicalRecordsImport;

class ImportMedicalRecords extends Command
{
    protected $signature = 'import:medical-records
        {path? : Path to xlsx relative to project root OR absolute path}
        {--deactivate-missing : Marks existing records inactive if not present in this import (optional)}
    ';

    protected $description = 'Import medical records and ICD code keys from an Excel file (multi-sheet).';

    public function handle(): int
    {
        $pathArg = $this->argument('path');

        $path = $pathArg
            ? (str_starts_with($pathArg, '/') ? $pathArg : base_path($pathArg))
            : storage_path('app/imports/medical_records.xlsx');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $this->info("Importing: {$path}");

        try {
            // Deterministic way to discover all worksheet names (stable across Laravel-Excel versions)
            $reader = IOFactory::createReaderForFile($path);
            $sheetNames = $reader->listWorksheetNames($path);

            if (empty($sheetNames)) {
                $this->warn('No sheets found in the Excel file.');
                return self::SUCCESS;
            }

            $this->info('Sheets: ' . implode(', ', $sheetNames));

            Excel::import(
                new MedicalRecordsImport(
                    sheetNames: $sheetNames,
                    deactivateMissing: (bool) $this->option('deactivate-missing')
                ),
                $path
            );

            $this->info("Import completed.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Import failed: ' . $e->getMessage());
            // Uncomment for debugging:
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}
