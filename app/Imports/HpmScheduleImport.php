<?php

namespace App\Imports;

use App\Contracts\ExcelImport;
use App\Models\Customer\HPM\Schedule;
use Illuminate\Support\Facades\Log;

class HpmScheduleImport implements ExcelImport
{
    public array $summary = [
        'total' => 0,
        'unique' => 0,
        'updated' => 0,
        'skipped' => 0,
    ];

    public function processRow(array $row, int $rowNumber): void
    {
        $this->summary['total']++;

        $slipNumber = isset($row['slip_number']) ? (string) $row['slip_number'] : null;

        if (!$slipNumber) {
            Log::warning('⚠️ slip_number kosong pada baris import, baris dilewati', ['row' => $rowNumber, 'row_data' => $row]);
            $this->summary['skipped']++;
            return;
        }

        $payload = [
            'schedule_date' => $this->parseDate($row['schedule_date'] ?? null),
            'adjusted_date' => $this->parseDate($row['adjusted_date'] ?? null),
            'schedule_time' => $this->parseTime($row['schedule_time'] ?? null),
            'adjusted_time' => $this->parseTime($row['adjusted_time'] ?? null),
            'delivery_quantity' => isset($row['delivery_quantity']) ? (int) $row['delivery_quantity'] : 0,
            'adjustment_quantity' => isset($row['adjustment_quantity']) ? (int) $row['adjustment_quantity'] : 0,
        ];

        Log::info('📝 Processing row', [
            'row' => $rowNumber,
            'slip_number' => $slipNumber,
            'payload' => $payload,
        ]);

        $model = Schedule::updateOrCreate(
            ['slip_number' => $slipNumber],
            $payload,
        );

        if ($model->wasRecentlyCreated) {
            $this->summary['unique']++;
            Log::info('✅ Schedule dibuat dari import', ['slip_number' => $slipNumber, 'id' => $model->id]);
        } else {
            $this->summary['updated']++;
            Log::info('♻️ Schedule diupdate dari import', ['slip_number' => $slipNumber, 'id' => $model->id]);
        }
    }

    public function rules(): array
    {
        return [
            'slip_number' => 'required|numeric',
            'schedule_date' => 'nullable',
            'adjusted_date' => 'nullable',
            'schedule_time' => 'nullable',
            'adjusted_time' => 'nullable',
            'delivery_quantity' => 'nullable|numeric',
            'adjustment_quantity' => 'nullable|numeric',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'slip_number.required' => 'Slip number is required',
            'slip_number.numeric' => 'Slip number must be numeric',
        ];
    }

    public function onError(\Throwable $e): void
    {
        $this->summary['skipped']++;
        Log::error('❌ Error saat import Schedule', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    public function getSummary(): array
    {
        return $this->summary;
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $str = trim((string) $value);

            if (preg_match('/^\d{8}$/', $str)) {
                $y = substr($str, 0, 4);
                $m = substr($str, 4, 2);
                $d = substr($str, 6, 2);
                $date = \Carbon\Carbon::createFromFormat('Y-m-d', "$y-$m-$d");
                Log::debug('📅 Parsed yyyymmdd date', ['input' => $str, 'output' => $date->format('Y-m-d')]);
                return $date->format('Y-m-d');
            }

            if (preg_match('/^\d{1,2}-[A-Za-z]{3}-\d{2,4}$/', $str)) {
                try {
                    $date = \Carbon\Carbon::createFromFormat('d-M-y', $str);
                    Log::debug('📅 Parsed dd-MMM-yy date', ['input' => $str, 'output' => $date->format('Y-m-d')]);
                    return $date->format('Y-m-d');
                } catch (\Exception $e) {
                    $date = \Carbon\Carbon::createFromFormat('d-M-Y', $str);
                    Log::debug('📅 Parsed dd-MMM-YYYY date', ['input' => $str, 'output' => $date->format('Y-m-d')]);
                    return $date->format('Y-m-d');
                }
            }

            if (is_numeric($str) && (float) $str > 1000) {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $str);
                Log::debug('📅 Parsed Excel serial date', ['input' => $str, 'output' => $date->format('Y-m-d')]);
                return $date->format('Y-m-d');
            }

            $date = \Carbon\Carbon::parse($str);
            Log::debug('📅 Parsed generic date', ['input' => $str, 'output' => $date->format('Y-m-d')]);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            Log::warning('⚠️ Gagal parse date', ['value' => $value, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function parseTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            $str = trim((string) $value);

            if (is_numeric($str) && strpos($str, '.') !== false && (float) $str < 1) {
                $time = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $str);
                Log::debug('⏰ Parsed Excel time fraction', ['input' => $str, 'output' => $time->format('H:i:s')]);
                return $time->format('H:i:s');
            }

            if (preg_match('/^\d{1,6}$/', $str)) {
                if (strlen($str) <= 4) {
                    $padded = str_pad($str, 4, '0', STR_PAD_LEFT) . '00';
                } else {
                    $padded = str_pad($str, 6, '0', STR_PAD_LEFT);
                }

                $h = (int) substr($padded, 0, 2);
                $m = (int) substr($padded, 2, 2);
                $s = (int) substr($padded, 4, 2);
                $h = $h % 24;
                $result = sprintf('%02d:%02d:%02d', $h, $m, $s);
                Log::debug('⏰ Parsed numeric time', ['input' => $str, 'padded' => $padded, 'output' => $result]);
                return $result;
            }

            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $str)) {
                $result = strlen($str) === 5 ? $str . ':00' : $str;
                Log::debug('⏰ Parsed H:i time', ['input' => $str, 'output' => $result]);
                return $result;
            }

            $time = \Carbon\Carbon::parse($str);
            Log::debug('⏰ Parsed generic time', ['input' => $str, 'output' => $time->format('H:i:s')]);
            return $time->format('H:i:s');
        } catch (\Exception $e) {
            Log::warning('⚠️ Gagal parse time', ['value' => $value, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
