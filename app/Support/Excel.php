<?php

namespace App\Support;

use App\Contracts\ExcelExport;
use App\Contracts\ExcelImport;
use App\Exceptions\ExcelValidationException;
use App\Exceptions\ExcelValidationFailure;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Excel
{
    /**
     * Generate and stream an Excel download from an export object.
     */
    public static function download(ExcelExport $export, string $filename, ?string $type = 'Xlsx'): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        static::writeHeadings($sheet, $export->headings());
        static::writeData($sheet, $export->data());

        $export->styles($sheet);

        if (method_exists($export, 'columnWidths')) {
            foreach ($export->columnWidths() as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }
        }

        if (method_exists($export, 'columnFormats')) {
            foreach ($export->columnFormats() as $range => $format) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
            }
        }

        if (method_exists($export, 'autoFilter')) {
            $export->autoFilter($sheet);
        }

        $writer = new Xlsx($spreadsheet);
        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx');
        $writer->save($tempPath);

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Process an uploaded spreadsheet through an import object.
     *
     * @throws ExcelValidationException
     */
    public static function import(ExcelImport $import, string $filePath, ?string $disk = null): ExcelImport
    {
        $fullPath = $disk ? Storage::disk($disk)->path($filePath) : $filePath;

        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray();
        if (empty($rows)) {
            return $import;
        }

        $headers = array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            array_shift($rows),
        );

        $rules = $import->rules();
        $messages = $import->customValidationMessages();

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            $rowData = static::normalizeRow($headers, $row);

            // Skip completely empty rows.
            if (empty(array_filter($rowData, static fn ($value) => $value !== null && $value !== ''))) {
                continue;
            }

            if ($rules !== []) {
                $validator = Validator::make($rowData, $rules, $messages);

                if ($validator->fails()) {
                    $errors = $validator->errors();
                    $firstAttribute = $errors->keys()[0];

                    throw new ExcelValidationException(
                        new ExcelValidationFailure(
                            $rowNumber,
                            $firstAttribute,
                            $errors->get($firstAttribute),
                        ),
                    );
                }
            }

            try {
                $import->processRow($rowData, $rowNumber);
            } catch (ExcelValidationException $e) {
                throw $e;
            } catch (\Throwable $e) {
                if (method_exists($import, 'onError')) {
                    $import->onError($e);
                    continue;
                }

                throw $e;
            }
        }

        return $import;
    }

    /**
     * Write the header row to the worksheet.
     */
    private static function writeHeadings(Worksheet $sheet, array $headings): void
    {
        foreach ($headings as $index => $heading) {
            $sheet->setCellValue([$index + 1, 1], $heading);
        }
    }

    /**
     * Write data rows to the worksheet.
     */
    private static function writeData(Worksheet $sheet, array $data): void
    {
        foreach ($data as $rowIndex => $row) {
            foreach ((array) $row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 2], $value);
            }
        }
    }

    /**
     * Build an associative array for a row using the header names as keys.
     */
    private static function normalizeRow(array $headers, array $row): array
    {
        $data = [];

        foreach ($headers as $index => $header) {
            $value = $row[$index] ?? null;
            $data[$header] = $value === '' ? null : $value;
        }

        return $data;
    }
}
