<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use Mary\Traits\Toast;
use App\Traits\Livewire\HasScannerLock;
use App\Traits\Livewire\ProcessesScan;
use App\Services\PccTraceService;
use App\Models\Customer\HPM\Pcc;
use App\Models\Customer\HPM\PccTrace;
use App\Models\Customer\HPM\PccEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    use Toast, HasScannerLock, ProcessesScan;

    public array $recentScans = [];
    public string $eventType = 'DELIVERY';
    public string $remarks = '';

    // Scanner identifier for global locking
    private const SCANNER_ID = 'weld-delivery';

    // Override unlock permission
    protected function getUnlockPermission(): string
    {
        return 'weld.unlock-scanner';
    }

    public function mount(): void
    {
        $this->loadRecentScans();
    }

    public function loadRecentScans(): void
    {
        $this->recentScans = PccTrace::with(['pcc:id,slip_no,part_no,part_name,slip_barcode'])
            ->where('event_type', $this->eventType)
            ->latest('event_timestamp')
            ->limit(20)
            ->get()
            ->map(fn($trace) => [
                'id' => $trace->id,
                'slip_no' => $trace->pcc->slip_no ?? 'N/A',
                'part_no' => $trace->pcc->part_no ?? 'N/A',
                'part_name' => $trace->pcc->part_name ?? 'N/A',
                'barcode' => $trace->pcc->slip_barcode ?? 'N/A',
                'timestamp' => $trace->event_timestamp->format('Y-m-d H:i:s'),
                'remarks' => $trace->remarks,
            ])
            ->toArray();
    }

    #[On('barcode-scanned')]
    public function processScan(string $barcode): void
    {
        try {
            // Check global lock state first
            if ($this->checkAndCleanupLock()) {
                return;
            }

            // Validate PCC barcode/slip
            $with = [
                'finishGood' => function ($q) {
                    $q->select('id', 'part_number', 'part_name', 'alias', 'type');
                }
            ];
            $pcc = $this->validateAndFetchPcc($barcode, $with);
            if (!$pcc) return;

            // Validate stage transition
            $trace = PccTraceService::getCurrentTrace($pcc);
            if ($trace) {
                // Already processed - validation will fail
                $this->validateStageAndCheckDuplicates($pcc, $trace, $this->eventType);
                return;
            }

            // Finalize the delivery check
            $this->finalizeDeliveryCheck($pcc);

        } catch (\Exception $e) {
            $this->logScanError('Weld Delivery', $barcode, $e);
            $this->showGenericError();
        }
    }

    // Finalize the check by creating PccTrace and PccEvent
    public function finalizeDeliveryCheck(Pcc $pcc): void
    {
        try {
            DB::beginTransaction();

            // Current state (PccTrace)
            $trace = PccTrace::create([
                'pcc_id' => $pcc->id,
                'event_type' => $this->eventType,
                'event_timestamp' => now(),
                'remarks' => $this->remarks ?: null,
            ]);

            // Historical log (PccEvent)
            PccEvent::create([
                'pcc_trace_id' => $trace->id,
                'event_users' => Auth::id(),
                'event_type' => $trace->event_type,
                'event_timestamp' => $trace->event_timestamp,
                'remarks' => $trace->remarks,
            ]);

            DB::commit();

            $partNumber = $pcc->finishGood->part_number ?? $pcc->part_no;
            $this->success("✓ {$partNumber} - {$pcc->slip_no}", null, 'toast-top');
            $this->dispatch('scan-feedback', type: 'success');
            
            // Notify trace page for live updates
            $this->dispatch('pcc-trace-updated', pccId: $pcc->id);
            
            $this->loadRecentScans();
            
            // Clear remarks after successful scan
            $this->remarks = '';
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log detailed error for debugging
            \Log::error('Weld Delivery - Finalization failed', [
                'user_id' => \Auth::id(),
                'pcc_id' => $pcc->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Show generic error to user
            $this->error(__('A system error occurred while saving data. Please try again.'), null, 'toast-top');
            $this->dispatch('scan-feedback', type: 'error');
        }
    }

    public function clearRemarks(): void
    {
        $this->remarks = '';
    }

    // No dynamic data needed for view
    public function with(): array { return []; }
}; ?>

<div>
    <x-header :title="__('Delivery Scanner')" separator>
        <x-slot:middle class="!justify-end">
            <x-button :label="__('Refresh')" icon="o-arrow-path" class="btn-sm" wire:click="loadRecentScans" />
        </x-slot:middle>
    </x-header>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Scanner Section --}}
        <div class="space-y-4">
            {{-- QR Scanner Component --}}
            <livewire:components.ui.qr-scanner 
                scanner-id="weld-delivery"
                :label="__('Scanner')"
                :placeholder="__('Scan or type barcode/slip number...')"
                :show-manual-input="true"
                :cooldown-seconds="3"
            />
        </div>

        {{-- Recent Scans Section --}}
        <div>
            <x-scanner.recent-scans
                :recentScans="$recentScans"
                :title="__('Recent Deliveries')"
                show-relative
                badge-icon="o-check"
                badge-class="badge-success" />
        </div>
    </div>

    {{-- Scanner Lock Overlay --}}
    <x-scanner.lock-overlay 
        :show="$this->scannerLocked"
        :lockRemainingSeconds="$this->lockRemainingSeconds"
        :canUnlock="auth()->user() && auth()->user()->can('weld.unlock-scanner')"
        :title="__('Scanner Locked')"
        :subtitle="__('Scanner temporarily inactive.')"
        :alertMessage="__('Scanner locked for system security. Contact supervisor if unlock is needed.')"
        :footerMessage="__('Please wait for the lock to expire or contact supervisor.')"
    />

    {{-- Audio Feedback --}}
    <x-scanner.audio-feedback />
</div>