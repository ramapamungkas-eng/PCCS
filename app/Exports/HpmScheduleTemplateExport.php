<?php

namespace App\Exports;

use App\Contracts\ExcelExport;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class HpmScheduleTemplateExport implements ExcelExport
{
    public function headings(): array
    {
        return [
            'slip_number',
            'schedule_date',
            'adjusted_date',
            'schedule_time',
            'adjusted_time',
            'delivery_quantity',
            'adjustment_quantity',
        ];
    }

    public function data(): array
    {
        return [
            ['254510000592', '20251031', '31-Oct-25', '90000', '13:00', 60, 30],
        ];
    }

    public function styles(Worksheet $sheet): void
    {
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $sheet->getStyle('A2:G100')->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ]);
    }

    public function columnWidths(): array
    {
        return [
            'A' => 20,
            'B' => 18,
            'C' => 18,
            'D' => 15,
            'E' => 15,
            'F' => 20,
            'G' => 20,
        ];
    }
}
