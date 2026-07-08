<?php

namespace App\Exports;

use App\Contracts\ExcelExport;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PccTemplateExport implements ExcelExport
{
    public function headings(): array
    {
        return [
            'from',
            'to',
            'supply_address',
            'next_supply_address',
            'ms_id',
            'inventory_category',
            'part_no',
            'part_name',
            'color_code',
            'ps_code',
            'order_class',
            'prod_seq_no',
            'kd_lot_no',
            'ship',
            'slip_no',
            'slip_barcode',
            'date',
            'time',
            'hns',
        ];
    }

    public function data(): array
    {
        return [];
    }

    public function styles(Worksheet $sheet): void
    {
        // No custom styling required for the plain template.
    }
}
