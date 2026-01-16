<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MedicalRecordsImport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $sheetNames,
        private readonly bool $deactivateMissing = false
    ) {}

    public function sheets(): array
    {
        $map = [];
        foreach ($this->sheetNames as $name) {
            $map[$name] = new MedicalRecordsSheetImport($this->deactivateMissing);
        }
        return $map;
    }
}
