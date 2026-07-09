@props([
    'recentScans' => [],
    'title' => __('Recent Scans'),
    'showRelative' => false,
    'badgeValue' => null,
    'badgeIcon' => null,
    'badgeClass' => 'badge-success',
])

<x-card :title="$title . ' (' . count($recentScans) . ')'" shadow>
    <div class="space-y-2 max-h-[700px] overflow-y-auto">
        @forelse($recentScans as $scan)
            <div class="p-3 bg-base-200 rounded-lg hover:bg-base-300 transition">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <div class="font-semibold text-sm">{{ $scan['slip_no'] ?? '-' }}</div>
                        <div class="text-xs text-gray-600 mt-1">
                            {{ ($scan['part_no'] ?? '-') . ' - ' . ($scan['part_name'] ?? '-') }}
                        </div>
                        @if(!empty($scan['remarks']))
                            <div class="text-xs text-gray-500 mt-1">{{ $scan['remarks'] }}</div>
                        @endif
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-500">
                            @if($showRelative)
                                {{ \Carbon\Carbon::parse($scan['timestamp'] ?? now())->diffForHumans() }}
                            @else
                                {{ $scan['timestamp'] ?? '-' }}
                            @endif
                        </div>
                        @if($badgeIcon || $badgeValue)
                            <x-badge :value="$badgeValue" :icon="$badgeIcon" class="{{ $badgeClass }} badge-sm mt-1" />
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="text-center py-8 text-gray-500">
                <x-icon name="o-qr-code" class="w-12 h-12 mx-auto mb-2 opacity-50" />
                <p>{{ __('No scans yet') }}</p>
            </div>
        @endforelse
    </div>
</x-card>
