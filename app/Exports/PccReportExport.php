<?php

namespace App\Exports;

use App\Contracts\ExcelExport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PccReportExport implements ExcelExport
{
    public function __construct(
        protected string $fromDate,
        protected ?string $toDate = null,
    ) {
        if (! $this->toDate) {
            $this->toDate = $this->fromDate;
        }
    }

    public function headings(): array
    {
        return [
            'Slip Barcode',
            'Finish Good Part Number',
            'Finish Good Part Name',
            'Production Checked At',
            'Production By',
            'Received At',
            'Received By',
            'PDI Checked At',
            'PDI By',
            'Delivery At',
            'Delivery By',
        ];
    }

    public function data(): array
    {
        $toCarbon = fn ($v) => $v ? Carbon::parse($v) : null;

        return $this->query()
            ->get()
            ->map(fn ($row) => [
                $row->barcode,
                $row->fg_part_number,
                $row->fg_part_name,
                $toCarbon($row->production_checked_at),
                $row->production_by,
                $toCarbon($row->received_at),
                $row->received_by,
                $toCarbon($row->pdi_checked_at),
                $row->pdi_by,
                $toCarbon($row->delivery_at),
                $row->delivery_by,
            ])
            ->toArray();
    }

    public function styles(Worksheet $sheet): void
    {
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:K1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFEAEAEA');
    }

    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_DATE_DATETIME,
            'F' => NumberFormat::FORMAT_DATE_DATETIME,
            'H' => NumberFormat::FORMAT_DATE_DATETIME,
            'J' => NumberFormat::FORMAT_DATE_DATETIME,
        ];
    }

    public function autoFilter(Worksheet $sheet): void
    {
        $sheet->setAutoFilter('A1:K1');
    }

    private function query()
    {
        $effectiveDateExpr = DB::raw('COALESCE(s.adjusted_date, s.schedule_date, p.date)');

        $latestEventTs = function (string $type) {
            return DB::table('hpm_pcc_events as e')
                ->select('e.event_timestamp')
                ->join('hpm_pcc_traces as t', 't.id', '=', 'e.pcc_trace_id')
                ->whereColumn('t.pcc_id', 'p.id')
                ->where('e.event_type', $type)
                ->orderByDesc('e.event_timestamp')
                ->limit(1);
        };

        $latestEventUser = function (string $type) {
            return DB::table('hpm_pcc_events as e')
                ->select('u.name')
                ->join('hpm_pcc_traces as t', 't.id', '=', 'e.pcc_trace_id')
                ->leftJoin('users as u', 'u.id', '=', 'e.event_users')
                ->whereColumn('t.pcc_id', 'p.id')
                ->where('e.event_type', $type)
                ->orderByDesc('e.event_timestamp')
                ->limit(1);
        };

        return DB::table('pccs as p')
            ->leftJoin('hpm_schedules as s', 's.slip_number', '=', 'p.slip_no')
            ->leftJoin('finish_goods as fg', 'fg.part_number', '=', 'p.part_no')
            ->whereBetween($effectiveDateExpr, [$this->fromDate, $this->toDate])
            ->select([
                'p.slip_barcode as barcode',
                DB::raw('COALESCE(fg.part_number, p.part_no) as fg_part_number'),
                DB::raw('COALESCE(fg.part_name, p.part_name) as fg_part_name'),
            ])
            ->selectSub($latestEventTs('PRODUCTION CHECK'), 'production_checked_at')
            ->selectSub($latestEventUser('PRODUCTION CHECK'), 'production_by')
            ->selectSub($latestEventTs('RECEIVED'), 'received_at')
            ->selectSub($latestEventUser('RECEIVED'), 'received_by')
            ->selectSub($latestEventTs('PDI CHECK'), 'pdi_checked_at')
            ->selectSub($latestEventUser('PDI CHECK'), 'pdi_by')
            ->selectSub($latestEventTs('DELIVERY'), 'delivery_at')
            ->selectSub($latestEventUser('DELIVERY'), 'delivery_by')
            ->orderBy('p.slip_barcode');
    }
}
