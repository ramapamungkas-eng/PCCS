<?php

namespace App\Contracts;

interface ExcelImport
{
    /**
     * Process a single row of imported data.
     *
     * @param  array  $row  Associative array keyed by lower-cased header names.
     * @param  int  $rowNumber  The 1-based spreadsheet row number (header is row 1).
     */
    public function processRow(array $row, int $rowNumber): void;

    /**
     * Laravel-style validation rules applied to each row.
     */
    public function rules(): array;

    /**
     * Custom validation messages for each row.
     */
    public function customValidationMessages(): array;

    /**
     * Return a summary of the import operation.
     */
    public function getSummary(): array;
}
