<?php

namespace App\Imports;

use App\Contracts\ExcelImport;
use App\Models\Customer\HPM\Pcc;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class HpmPccImport implements ExcelImport
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

        $slipBarcode = isset($row['slip_barcode']) ? (string) $row['slip_barcode'] : null;
        if (!$slipBarcode) {
            Log::warning('slip_barcode kosong pada baris import PCC, baris dilewati', [
                'row' => $rowNumber,
            ]);
            $this->summary['skipped']++;
            return;
        }

        $dateString = $row['date'] ?? null;
        $timeString = $row['time'] ?? null;

        $convertedDate = null;
        $convertedTime = null;

        if ($dateString) {
            try {
                $convertedDate = Carbon::createFromFormat('d-m', (string) $dateString)
                    ->year(now()->year)
                    ->toDateString();
            } catch (\Exception $e) {
                if (!empty($dateString)) {
                    Log::warning('Format tanggal tidak valid pada baris import PCC', [
                        'row' => $rowNumber,
                        'slip_barcode' => $slipBarcode,
                        'date_string' => $dateString,
                    ]);
                }
                $convertedDate = null;
            }
        }

        if ($timeString) {
            try {
                $convertedTime = Carbon::createFromFormat('H:i', (string) $timeString)->toTimeString();
            } catch (\Exception $e) {
                if (!empty($timeString)) {
                    Log::warning('Format waktu tidak valid pada baris import PCC', [
                        'row' => $rowNumber,
                        'slip_barcode' => $slipBarcode,
                        'time_string' => $timeString,
                    ]);
                }
                $convertedTime = null;
            }
        }

        $payload = [
            'from' => $row['from'] ?? null,
            'to' => $row['to'] ?? null,
            'supply_address' => $row['supply_address'] ?? null,
            'next_supply_address' => $row['next_supply_address'] ?? null,
            'ms_id' => $row['ms_id'] ?? null,
            'inventory_category' => $row['inventory_category'] ?? null,
            'part_no' => $row['part_no'] ?? null,
            'part_name' => $row['part_name'] ?? null,
            'color_code' => $row['color_code'] ?? null,
            'ps_code' => $row['ps_code'] ?? null,
            'order_class' => $row['order_class'] ?? null,
            'prod_seq_no' => $row['prod_seq_no'] ?? null,
            'kd_lot_no' => $row['kd_lot_no'] ?? null,
            'ship' => isset($row['ship']) ? (int) $row['ship'] : null,
            'slip_no' => $row['slip_no'] ?? null,
            'date' => $convertedDate,
            'time' => $convertedTime,
            'hns' => $row['hns'] ?? null,
        ];

        try {
            $model = Pcc::updateOrCreate(
                ['slip_barcode' => $slipBarcode],
                $payload + ['slip_barcode' => $slipBarcode],
            );

            if ($model->wasRecentlyCreated) {
                $this->summary['unique']++;
            } else {
                $this->summary['updated']++;
            }
        } catch (\Throwable $e) {
            Log::error('Error saat import PCC', [
                'row' => $rowNumber,
                'slip_barcode' => $slipBarcode,
                'error' => $e->getMessage(),
                'error_type' => get_class($e),
            ]);
            $this->summary['skipped']++;
        }
    }

    public function rules(): array
    {
        return [];
    }

    public function customValidationMessages(): array
    {
        return [];
    }

    public function onError(\Throwable $e): void
    {
        $this->summary['skipped']++;
        Log::error('Error saat import PCC (SkipsOnError)', [
            'error' => $e->getMessage(),
            'error_type' => get_class($e),
        ]);
    }

    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getRowCount(): int
    {
        return $this->summary['total'] ?? 0;
    }

    public function getDuplicateCount(): int
    {
        return $this->summary['skipped'] ?? 0;
    }

    public function getUniqueCount(): int
    {
        return ($this->summary['unique'] ?? 0) + ($this->summary['updated'] ?? 0);
    }
}
