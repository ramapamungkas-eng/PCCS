<?php

declare(strict_types=1);

namespace App\Services\Ppic;

use App\Models\Customer\HPM\Pcc;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    private const DELIVERY_EVENT = 'DELIVERY';

    /**
     * Compute PPIC dashboard statistics for the given date.
     *
     * @return array<string, int>
     */
    public function getStats(string $date): array
    {
        $plannedQuery = Pcc::withEffectiveDate()
            ->whereRaw('COALESCE(hpm_schedules.adjusted_date, hpm_schedules.schedule_date, pccs.date) = ?', [$date]);

        $plannedCount = (clone $plannedQuery)->count();

        $planned = (clone $plannedQuery)
            ->with([
                'events' => fn ($q) => $q->where('hpm_pcc_events.event_type', self::DELIVERY_EVENT)
                    ->select(['hpm_pcc_events.id', 'hpm_pcc_events.pcc_trace_id', 'hpm_pcc_events.event_type', 'hpm_pcc_events.event_timestamp']),
            ])
            ->get(['pccs.id', 'pccs.part_no', 'pccs.part_name', 'pccs.slip_no', 'pccs.date', 'pccs.time']);

        $delivered = $planned->filter(
            fn (Pcc $pcc) => $pcc->events !== null && $pcc->events->isNotEmpty()
        );

        $deliveredCount = $delivered->count();
        $pending = $plannedCount - $deliveredCount;

        $late = Pcc::withEffectiveDate()
            ->whereRaw('COALESCE(hpm_schedules.adjusted_date, hpm_schedules.schedule_date, pccs.date) <= ?', [$date])
            ->whereDoesntHave('events', fn ($q) => $q->where('hpm_pcc_events.event_type', self::DELIVERY_EVENT))
            ->count();

        $onTime = $delivered->filter(fn (Pcc $pcc) => $this->isDeliveryOnTime($pcc))->count();

        $successRate = $plannedCount > 0 ? (int) round(($deliveredCount / $plannedCount) * 100) : 0;
        $plannedQty = $planned->unique('part_no')->count();
        $deliveredQty = $delivered->unique('part_no')->count();

        return compact(
            'plannedCount',
            'plannedQty',
            'deliveredCount',
            'deliveredQty',
            'pending',
            'late',
            'onTime',
            'successRate'
        );
    }

    /**
     * Get a summary of today's planned deliveries grouped by part number.
     */
    public function getRecentSummary(string $date): Collection
    {
        $planned = Pcc::withEffectiveDate()
            ->whereRaw('COALESCE(hpm_schedules.adjusted_date, hpm_schedules.schedule_date, pccs.date) = ?', [$date])
            ->with([
                'events' => fn ($q) => $q->where('hpm_pcc_events.event_type', self::DELIVERY_EVENT)
                    ->select(['hpm_pcc_events.id', 'hpm_pcc_events.pcc_trace_id', 'hpm_pcc_events.event_type', 'hpm_pcc_events.event_timestamp']),
            ])
            ->get(['pccs.id', 'pccs.part_no', 'pccs.part_name', 'pccs.slip_no']);

        return $planned
            ->groupBy('part_no')
            ->map(function (Collection $group) {
                $first = $group->first();
                $total = $group->count();
                $delivered = $group->filter(
                    fn (Pcc $p) => $p->events !== null && $p->events->isNotEmpty()
                )->count();

                $progress = $delivered.' / '.$total;
                $status = $delivered === $total ? __('Complete') : ($delivered > 0 ? __('Partial') : __('Pending'));
                $statusColor = $delivered === $total ? 'badge-success' : ($delivered > 0 ? 'badge-warning' : 'badge-ghost');

                return (object) [
                    'partNo' => $first->part_no,
                    'partName' => $first->part_name,
                    'slipNo' => $first->slip_no,
                    'totalQty' => $total,
                    'progress' => $progress,
                    'status' => $status,
                    'statusColor' => $statusColor,
                ];
            })
            ->sortByDesc('totalQty')
            ->values()
            ->take(10);
    }

    /**
     * Get the top 5 parts with the most late (undelivered) items up to the given date.
     */
    public function getTopLateParts(string $date): Collection
    {
        return Pcc::withEffectiveDate()
            ->whereRaw('COALESCE(hpm_schedules.adjusted_date, hpm_schedules.schedule_date, pccs.date) <= ?', [$date])
            ->whereDoesntHave('events', fn ($q) => $q->where('hpm_pcc_events.event_type', self::DELIVERY_EVENT))
            ->select('pccs.part_no', 'pccs.part_name', DB::raw('count(*) as late_count'))
            ->groupBy('pccs.part_no', 'pccs.part_name')
            ->orderByDesc('late_count')
            ->limit(5)
            ->get()
            ->map(fn (Pcc $pcc) => (object) [
                'part_no' => $pcc->part_no,
                'part_name' => $pcc->part_name,
                'late_count' => (int) $pcc->late_count,
            ]);
    }

    /**
     * Build the delay-modal rows for a given part number and date.
     *
     * @return array<int, object{date:string, days:int}>
     */
    public function getDelayRows(string $partNo, string $date): array
    {
        $lateDates = Pcc::withEffectiveDate()
            ->where('pccs.part_no', $partNo)
            ->whereRaw('COALESCE(hpm_schedules.adjusted_date, hpm_schedules.schedule_date, pccs.date) <= ?', [$date])
            ->whereDoesntHave('events', fn ($q) => $q->where('hpm_pcc_events.event_type', self::DELIVERY_EVENT))
            ->pluck('effective_date_alias')
            ->filter()
            ->map(fn (string $d) => $d)
            ->sort()
            ->values();

        $asOf = CarbonImmutable::parse($date);

        return $lateDates
            ->map(function (string $d) use ($asOf) {
                $ed = Carbon::immutable($d);
                $days = $ed->greaterThan($asOf) ? 0 : $ed->diffInDays($asOf);

                return (object) [
                    'date' => $d,
                    'days' => (int) $days,
                ];
            })
            ->all();
    }

    /**
     * Determine whether the first delivery event for a PCC happened on or before
     * its effective date/time.
     */
    private function isDeliveryOnTime(Pcc $pcc): bool
    {
        $deliveryEvent = $pcc->events->first();

        if (! $deliveryEvent) {
            return false;
        }

        $effectiveDate = $pcc->effective_date_alias;
        if (! $effectiveDate) {
            return false;
        }

        $effectiveTime = $pcc->effective_time_alias ?: ($pcc->time ?: '23:59:59');
        $effectiveDateTime = Carbon::parse($effectiveDate.' '.substr($effectiveTime, 0, 8));

        return $deliveryEvent->event_timestamp <= $effectiveDateTime;
    }
}
