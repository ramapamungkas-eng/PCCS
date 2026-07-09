<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use App\Services\Ppic\DashboardService;
use App\Support\CacheHelper;

new
#[Title('PPIC Dashboard')]
class extends Component {
    private DashboardService $dashboard;

    public string $selectedDate;

    // Modal state for simple delay details
    public bool $delayDialogOpen = false;
    public string $delayPartNo = '';
    public string $delayEffectiveDate = '';
    public int $delayCount = 0;
    public int $delayDays = 0;
    public array $delayDateRows = [];

    public function boot(DashboardService $dashboard): void
    {
        $this->dashboard = $dashboard;
    }

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function openDelayModal(string $partNo): void
    {
        $this->delayPartNo = $partNo;

        $rows = $this->dashboard->getDelayRows($partNo, $this->selectedDate);
        $this->delayDateRows = $rows;
        $this->delayCount = count($rows);

        if (empty($rows)) {
            $this->delayEffectiveDate = $this->selectedDate;
            $this->delayDays = 0;
            $this->delayDialogOpen = true;

            return;
        }

        $this->delayEffectiveDate = collect($rows)->pluck('date')->min() ?: $this->selectedDate;

        $asOf = CarbonImmutable::parse($this->selectedDate);
        $eff = CarbonImmutable::parse($this->delayEffectiveDate);
        $this->delayDays = $eff->greaterThan($asOf) ? 0 : $eff->diffInDays($asOf);

        $this->delayDialogOpen = true;
    }

    #[Computed]
    public function stat(): array
    {
        $key = 'ppic_stat_' . $this->selectedDate;
        $tags = ['ppic_stats', 'ppic_date_' . $this->selectedDate];

        return CacheHelper::rememberTagged($tags, $key, 300, fn () => $this->dashboard->getStats($this->selectedDate));
    }

    #[Computed]
    public function recentSummary(): Collection
    {
        $key = 'ppic_recent_' . $this->selectedDate;
        $tags = ['ppic_summaries', 'ppic_date_' . $this->selectedDate];

        return CacheHelper::rememberTagged($tags, $key, 300, fn () => $this->dashboard->getRecentSummary($this->selectedDate));
    }

    #[Computed]
    public function topLateParts(): Collection
    {
        $key = 'ppic_top_late_' . $this->selectedDate;
        $tags = ['ppic_late_parts', 'ppic_date_' . $this->selectedDate];

        return CacheHelper::rememberTagged($tags, $key, 300, fn () => $this->dashboard->getTopLateParts($this->selectedDate));
    }

    /**
     * Clear PPIC dashboard caches.
     */
    public function clearDashboardCache(?string $date = null): void
    {
        if ($date) {
            CacheHelper::flushTagged(['ppic_date_' . $date]);
        } else {
            CacheHelper::flushTagged(['ppic_stats', 'ppic_summaries', 'ppic_late_parts']);
        }
    }

    /**
     * Refresh current view by clearing cache for selected date.
     */
    public function refreshData(): void
    {
        $this->clearDashboardCache($this->selectedDate);
        $this->dispatch('$refresh');
    }

    public function with(): array
    {
        return [
            'stat' => $this->stat,
            'recent' => $this->recentSummary,
            'topLate' => $this->topLateParts,
        ];
    }
}; ?>

<div class="space-y-6">
    <x-header :title="__('HPM Dashboard')" :subtitle="__('Overview of HPM Operations')" separator progress-indicator>
        <x-slot:actions>
            <x-input type="date" wire:model.live="selectedDate" class="min-w-48" />
            <x-button icon="o-arrow-path" class="btn-ghost" wire:click="refreshData" spinner="refreshData">{{ __('Refresh') }}</x-button>
        </x-slot:actions>
    </x-header>

    <!-- Main stats -->
    <div class="grid gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
        <x-card class="shadow-sm border border-base-300">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm opacity-70">{{ __('Planned (items)') }}</div>
                    <div class="text-3xl font-extrabold">{{ number_format($stat['plannedCount']) }}</div>
                </div>
                <x-icon name="o-calendar-days" class="w-10 h-10 text-primary/70" />
            </div>
            <div class="mt-2 text-xs opacity-70">{{ __('Planned Qty:') }} {{ number_format($stat['plannedQty']) }}</div>
        </x-card>

        <x-card class="shadow-sm border border-base-300">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm opacity-70">{{ __('Delivered Today') }}</div>
                    <div class="text-3xl font-extrabold">{{ number_format($stat['deliveredCount']) }}</div>
                </div>
                <x-icon name="o-check-badge" class="w-10 h-10 text-success/70" />
            </div>
            <div class="mt-2 text-xs opacity-70">{{ __('Delivered Qty:') }} {{ number_format($stat['deliveredQty']) }}</div>
        </x-card>

        <x-card class="shadow-sm border border-base-300">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm opacity-70">{{ __('Pending') }}</div>
                    <div class="text-3xl font-extrabold">{{ number_format($stat['pending']) }}</div>
                </div>
                <x-icon name="o-clock" class="w-10 h-10 text-warning/70" />
            </div>
        </x-card>

        <x-card class="shadow-sm border border-base-300">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-sm opacity-70">{{ __('Late') }}</div>
                    <div class="text-3xl font-extrabold">{{ number_format($stat['late']) }}</div>
                </div>
                <x-icon name="o-exclamation-triangle" class="w-10 h-10 text-error/70" />
            </div>
        </x-card>
    </div>

    <!-- Success rate & daily stats -->
    <div class="grid gap-4 lg:grid-cols-3">
        <x-card class="lg:col-span-2 border border-base-300">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <div class="text-lg font-bold">{{ __('Success Rate') }}</div>
                    <div class="text-xs opacity-70">{{ __('Target ≥ 95%') }}</div>
                </div>
                <div class="text-4xl font-extrabold {{ $stat['successRate'] >= 95 ? 'text-success' : ($stat['successRate'] >= 80 ? 'text-warning' : 'text-error') }}">{{ $stat['successRate'] }}%</div>
            </div>
            <div class="w-full h-4 rounded-xl bg-base-300 overflow-hidden">
                <div class="h-4 rounded-xl {{ $stat['successRate'] >= 95 ? 'bg-success' : ($stat['successRate'] >= 80 ? 'bg-warning' : 'bg-error') }}" style="width: {{ $stat['successRate'] }}%"></div>
            </div>
            <div class="flex justify-between text-xs opacity-60 mt-2">
                <span>0%</span><span>25%</span><span>50%</span><span>75%</span><span>100%</span>
            </div>
            <div class="mt-2 text-xs opacity-70">{{ number_format($stat['deliveredCount']) }} {{ __('of') }} {{ number_format($stat['plannedCount']) }} {{ __('items') }}</div>
        </x-card>

        <x-card class="border border-base-300">
            <div class="text-lg font-bold mb-2">{{ __('Daily Stats') }}</div>
            <div class="text-sm opacity-70 mb-3"><x-format.date :value="$selectedDate" format="d M Y" /></div>
            <div class="space-y-2">
                <div class="flex items-center justify-between p-3 rounded-xl border bg-success/10 border-success/30">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-success text-success-content rounded-lg grid place-items-center">
                            <x-icon name="o-check" class="w-4 h-4" />
                        </div>
                        <span class="font-medium">{{ __('On Time') }}</span>
                    </div>
                    <span class="font-bold">{{ number_format($stat['onTime']) }}</span>
                </div>
                <div class="flex items-center justify-between p-3 rounded-xl border bg-error/10 border-error/30">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 bg-error text-error-content rounded-lg grid place-items-center">
                            <x-icon name="o-exclamation-triangle" class="w-4 h-4" />
                        </div>
                        <span class="font-medium">{{ __('Late (open)') }}</span>
                    </div>
                    <span class="font-bold">{{ number_format($stat['late']) }}</span>
                </div>
            </div>
        </x-card>
    </div>

    <!-- Tables -->
    <div class="grid gap-4 lg:grid-cols-2">
        <x-card :title="__('Today\'s Delivery Summary')" :subtitle="__('Per part number')" class="border border-base-300">
            <x-table :headers="[
                ['key' => 'part', 'label' => __('Part Details')],
                ['key' => 'slip', 'label' => __('Slip No'), 'class' => 'text-center w-32'],
                ['key' => 'qty', 'label' => __('Qty'), 'class' => 'text-center w-16'],
                ['key' => 'progress', 'label' => __('Progress'), 'class' => 'text-center w-28'],
                ['key' => 'status', 'label' => __('Status'), 'class' => 'text-center w-24'],
            ]" :rows="$recent">
                @scope('cell_part', $row)
                    <div class="cursor-pointer" wire:click="openDelayModal('{{ $row->partNo }}')">
                        <div class="font-bold text-primary hover:underline">{{ $row->partNo }}</div>
                        <div class="text-xs opacity-70">{{ Str::limit($row->partName ?? '', 40) }}</div>
                    </div>
                @endscope
                @scope('cell_slip', $row)
                    <div class="text-center text-sm font-mono">{{ $row->slipNo ?? '-' }}</div>
                @endscope
                @scope('cell_qty', $row)
                    <div class="text-center font-bold">{{ $row->totalQty }}</div>
                @endscope
                @scope('cell_progress', $row)
                    <div class="text-center text-sm">{{ $row->progress }}</div>
                @endscope
                @scope('cell_status', $row)
                    <div class="text-center">
                        <span class="badge {{ $row->statusColor }} font-bold">{{ $row->status }}</span>
                    </div>
                @endscope
            </x-table>
        </x-card>

        <x-card :title="__('Top 5 Late Items')" :subtitle="__('For selected date')" class="border border-base-300">
            <div class="space-y-3">
                @forelse($topLate as $index => $part)
                    <div class="flex items-center gap-4 p-3 rounded-xl border bg-error/5 border-error/20">
                        <div class="w-8 h-8 bg-error text-error-content rounded-full grid place-items-center font-bold">{{ $index + 1 }}</div>
                        <div class="flex-1 cursor-pointer" wire:click="openDelayModal('{{ $part->part_no }}')">
                            <div class="font-bold text-primary hover:underline">{{ $part->part_no }}</div>
                            <div class="text-xs opacity-70">{{ Str::limit($part->part_name ?? '', 35) }}</div>
                        </div>
                        <div class="px-3 py-1 rounded-lg bg-error text-error-content font-bold">{{ $part->late_count }}x</div>
                    </div>
                @empty
                    <div class="text-center py-10 opacity-70">{{ __('No delays') }}</div>
                @endforelse
            </div>
        </x-card>
    </div>

    <!-- Delay Details Modal -->
    <x-modal wire:model="delayDialogOpen" :title="__('Delay Details')" :subtitle="__('Simple info for the selected part')">
        <div class="space-y-4">
            <x-card class="border border-base-300">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center">
                    <div class="text-sm opacity-70">{{ __('Part Number') }}</div>
                    <div class="sm:col-span-2 font-bold font-mono">{{ $delayPartNo }}</div>
                </div>
                <div class="divider my-3"></div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center">
                    <div class="text-sm opacity-70">{{ __('Effective Date') }}</div>
                    <div class="sm:col-span-2 font-bold"><x-format.date :value="$delayEffectiveDate" format="d M Y" /></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center mt-3">
                    <div class="text-sm opacity-70">{{ __('Delay Date') }}</div>
                    <div class="sm:col-span-2 font-bold"><x-format.date :value="$selectedDate" format="d M Y" /></div>
                </div>
                <div class="divider my-3"></div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center">
                    <div class="text-sm opacity-70">{{ __('Delayed Items') }}</div>
                    <div class="sm:col-span-2"><span class="badge badge-error font-bold">{{ number_format($delayCount) }}</span></div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-center mt-3">
                    <div class="text-sm opacity-70">{{ __('Days Late') }}</div>
                    <div class="sm:col-span-2"><span class="badge badge-ghost font-bold">{{ number_format($delayDays) }} {{ $delayDays === 1 ? __('day') : __('days') }}</span></div>
                </div>
            </x-card>

            <x-card :title="__('Delay Breakdown')" :subtitle="__('Per effective date up to selected date')" class="border border-base-300">
                @php
                    $dateGroups = collect($delayDateRows)
                        ->groupBy('date')
                        ->map(function ($rows, $date) {
                            return [
                                'date' => $date,
                                'count' => count($rows),
                                'days' => $rows[0]->days ?? 0,
                            ];
                        })
                        ->sortBy('date')
                        ->values();
                @endphp
                <x-table :headers="[
                    ['key' => 'date', 'label' => __('Date'), 'class' => 'w-40'],
                    ['key' => 'count', 'label' => __('Count'), 'class' => 'w-24 text-center'],
                    ['key' => 'days', 'label' => __('Delayed Days'), 'class' => 'w-36 text-center'],
                ]" :rows="$dateGroups">
                    @scope('cell_date', $row)
                        <div class="font-mono"><x-format.date :value="$row['date']" format="Y-m-d" /></div>
                    @endscope
                    @scope('cell_count', $row)
                        <div class="text-center">
                            <span class="badge badge-primary font-bold">{{ $row['count'] }}x</span>
                        </div>
                    @endscope
                    @scope('cell_days', $row)
                        <div class="text-center font-bold">{{ $row['days'] }}</div>
                    @endscope
                </x-table>
            </x-card>
        </div>

        <x-slot:actions>
            <x-button icon="o-x-mark" class="btn-ghost" wire:click="$set('delayDialogOpen', false)">{{ __('Close') }}</x-button>
        </x-slot:actions>
    </x-modal>
</div>
