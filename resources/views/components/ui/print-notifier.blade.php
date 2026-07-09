<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Notifications\PrintJobComplete;
use Illuminate\Support\Facades\Cache;

new class extends Component {

    public bool $showModal = false;
    public bool $isProcessing = false;
    public ?string $downloadUrl = null;
    public ?string $statusMessage = null;

    public int $progressPercent = 0;
    public string $progressStatus = 'processing';

    public int $pollCount = 0;
    public int $maxPolls = 60;
    public bool $hasTimedOut = false;
    public ?string $startedAt = null;

    /**
     * Cek status saat komponen pertama kali dimuat.
     */
    public function mount(): void
    {
        if (auth()->check()) {
            $this->checkStatus(false);
        }
    }

    /**
     * Dipicu oleh parent component saat job dicetak.
     */
    #[On('print-job-started')]
    public function startProcessing(): void
    {
        if (! auth()->check()) {
            return;
        }

        // Jika job berjalan sync atau worker sudah selesai sebelum modal dibuka,
        // langsung tampilkan hasil tanpa menghapus notifikasi yang baru dibuat.
        if ($this->detectCompletion()) {
            return;
        }

        // Hanya tandai notifikasi lama sebagai sudah dibaca; jangan dihapus.
        auth()->user()
            ->unreadNotifications()
            ->where('type', PrintJobComplete::class)
            ->update(['read_at' => now()]);

        $this->reset([
            'isProcessing', 'downloadUrl', 'statusMessage',
            'progressPercent', 'progressStatus',
            'pollCount', 'hasTimedOut', 'startedAt',
        ]);

        $this->isProcessing = true;
        $this->progressStatus = 'processing';
        $this->progressPercent = 0;
        $this->statusMessage = __('Preparing print job...');
        $this->startedAt = now()->toDateTimeString();
        $this->showModal = true;

        $this->checkProgress();
    }

    /**
     * Cek notifikasi dan cache progress secara berkala.
     */
    public function checkStatus(bool $markAsRead = true): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $this->pollCount++;

        $notification = $user
            ->unreadNotifications()
            ->where('type', PrintJobComplete::class)
            ->latest()
            ->first();

        if ($notification) {
            $this->applyCompletion(
                $notification->data['download_url'] ?? null,
                $notification->data['message'] ?? __('Status unknown.')
            );

            if ($markAsRead) {
                $notification->markAsRead();
            }

            return;
        }

        $this->checkProgress();
        $this->checkTimeout();
    }

    /**
     * Cek apakah job sudah selesai (sync/worker lebih dulu) dari cache atau notifikasi.
     */
    private function detectCompletion(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $progress = Cache::store(config('app.print_progress_cache_store'))->get("print-progress:{$user->id}");

        if (is_array($progress) && in_array($progress['status'] ?? '', ['completed', 'failed'], true)) {
            $this->applyCompletion(
                $progress['download_url'] ?? null,
                $progress['message'] ?? __('Status unknown.')
            );

            return true;
        }

        $notification = $user
            ->unreadNotifications()
            ->where('type', PrintJobComplete::class)
            ->latest()
            ->first();

        if ($notification) {
            $this->applyCompletion(
                $notification->data['download_url'] ?? null,
                $notification->data['message'] ?? __('Status unknown.')
            );
            $notification->markAsRead();

            return true;
        }

        return false;
    }

    /**
     * Baca progress dari cache yang ditulis oleh job.
     */
    private function checkProgress(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $progress = Cache::store(config('app.print_progress_cache_store'))->get("print-progress:{$user->id}");

        if (! is_array($progress)) {
            return;
        }

        $this->progressStatus = $progress['status'] ?? 'processing';
        $this->progressPercent = $progress['progress'] ?? 0;
        $this->statusMessage = $progress['message'] ?? $this->statusMessage;

        if (in_array($this->progressStatus, ['completed', 'failed'], true)) {
            $this->applyCompletion(
                $progress['download_url'] ?? null,
                $progress['message'] ?? $this->statusMessage
            );
        }
    }

    /**
     * Deteksi timeout agar modal tidak berputar selamanya.
     */
    private function checkTimeout(): void
    {
        if ($this->pollCount < $this->maxPolls) {
            return;
        }

        $this->hasTimedOut = true;
        $this->isProcessing = false;
        $this->progressStatus = 'timeout';
        $this->statusMessage = __('This is taking longer than expected. You can close this and check your notifications later.');
    }

    /**
     * Ubah state ke tampilan hasil (sukses/gagal).
     */
    private function applyCompletion(?string $downloadUrl, string $message): void
    {
        $this->isProcessing = false;
        $this->downloadUrl = $downloadUrl;
        $this->statusMessage = $message;
        $this->progressPercent = 100;
        $this->progressStatus = $downloadUrl ? 'completed' : 'failed';
        $this->showModal = true;
    }

    /**
     * Menutup modal dan reset state.
     */
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->isProcessing = false;
        $this->downloadUrl = null;
        $this->statusMessage = null;
        $this->progressPercent = 0;
        $this->progressStatus = 'processing';
        $this->pollCount = 0;
        $this->hasTimedOut = false;
        $this->startedAt = null;
    }
}; ?>

<div>
    <x-modal wire:model="showModal"
             :title="$isProcessing ? __('Creating labels...') : ($hasTimedOut ? __('Still Processing') : __('Print Finished!'))"
             class="!p-0"
             persistent
             separator>

        @if ($isProcessing)
            <div class="p-6 flex flex-col items-center justify-center space-y-4" wire:poll.2000ms="checkStatus">
                <svg class="animate-spin h-10 w-10 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>

                <div class="text-lg font-semibold text-base-content">
                    {{ __('Creating labels...') }}
                </div>

                @if ($progressPercent > 0)
                    <div class="w-full max-w-xs">
                        <div class="flex justify-between text-xs text-base-content/70 mb-1">
                            <span>{{ $statusMessage }}</span>
                            <span>{{ $progressPercent }}%</span>
                        </div>
                        <div class="w-full bg-base-300 rounded-full h-2">
                            <div class="bg-primary h-2 rounded-full transition-all duration-500" style="width: {{ $progressPercent }}%"></div>
                        </div>
                    </div>
                @else
                    <div class="text-sm text-base-content/70">
                        {{ $statusMessage ?: __('Please wait, the process may take a few moments.') }}
                    </div>
                @endif

                <div class="text-xs text-info">
                    {{ __('Status will be updated automatically.') }}
                </div>
            </div>
        @else
            <div class="p-6 flex flex-col items-center justify-center space-y-4">
                @if ($downloadUrl)
                    <x-icon name="o-check-circle" class="w-12 h-12 text-success" />
                    <div class="text-lg font-semibold text-base-content">
                        {{ __('Your File is Ready to Download.') }}
                    </div>
                    <div class="text-sm text-base-content/70 text-center">
                        {{ $statusMessage ?: __('Click the button below to download the document.') }}
                    </div>
                @elseif ($hasTimedOut)
                    <x-icon name="o-clock" class="w-12 h-12 text-warning" />
                    <div class="text-lg font-semibold text-warning">
                        {{ __('Still Processing') }}
                    </div>
                    <div class="text-sm text-base-content/70 text-center">
                        {{ $statusMessage }}
                    </div>
                @else
                    <x-icon name="o-exclamation-triangle" class="w-12 h-12 text-error" />
                    <div class="text-lg font-semibold text-error">
                        {{ __('An Error Occurred!') }}
                    </div>
                    <div class="text-sm text-base-content/70 text-center">
                        {{ $statusMessage ?: __('File creation failed due to internal issues.') }}
                    </div>
                @endif
            </div>
        @endif

        <x-slot:actions>
            @if ($isProcessing)
                <x-button :label="__('Close')" wire:click="closeModal" class="btn-ghost" />
                <x-button :label="__('Check now')" wire:click="checkStatus" class="btn-primary" spinner="checkStatus" />
            @else
                @if ($downloadUrl)
                    <a href="{{ $downloadUrl }}" target="_blank" class="btn btn-primary" @click="$wire.closeModal()">
                        <x-icon name="o-arrow-down-tray" class="w-5 h-5" />
                        {{ __('Download File') }}
                    </a>
                @endif

                <x-button :label="__('Close')" wire:click="closeModal" class="btn-ghost" />
            @endif
        </x-slot:actions>
    </x-modal>
</div>
