<?php

namespace App\Contracts;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

interface ExcelExport
{
    /**
     * Return the header row values.
     */
    public function headings(): array;

    /**
     * Return the data rows. Each row should be an array of scalar values.
     */
    public function data(): array;

    /**
     * Apply styles to the worksheet after data has been written.
     */
    public function styles(Worksheet $sheet): void;
}
