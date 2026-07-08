<?php

namespace App\Exports;

use App\Contracts\ExcelExport;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FinishGoodTemplateExport implements ExcelExport
{
    public function headings(): array
    {
        return [
            'customer_code',
            'part_number',
            'part_name',
            'alias',
            'model',
            'variant',
            'wh_address',
            'type',
            'stock',
            'is_active',
        ];
    }

    public function data(): array
    {
        return [
            ['HPM', 'ABC123', 'FRONT BUMPER', 'FB-01', 'BRIO', 'RS', 'A1-B2', 'ASSY', '0', '1'],
            ['HPM', 'XYZ789', 'REAR BUMPER', 'RB-01', 'CIVIC', 'Type R', 'C3-D4', 'DIRECT', '100', '1'],
        ];
    }

    public function styles(Worksheet $sheet): void
    {
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFFFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        foreach ([2, 3] as $row) {
            $sheet->getStyle("A{$row}:J{$row}")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE7E6E6');
        }
    }

    public function columnWidths(): array
    {
        return [
            'A' => 18,
            'B' => 20,
            'C' => 30,
            'D' => 15,
            'E' => 15,
            'F' => 15,
            'G' => 15,
            'H' => 12,
            'I' => 12,
            'J' => 12,
        ];
    }
}
