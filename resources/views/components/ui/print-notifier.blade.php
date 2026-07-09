<?php

use Livewire\Volt\Component;
use Livewire\Attributes\On;
use App\Notifications\PrintJobComplete;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;

new class extends Component {

    public bool $showSection = false;
    public bool $isProcessing = false;
    public bool $isCompleted = false;
    public bool $hasError = false;
    public bool $hasTimedOut = false;

    public ?string $downloadUrl = null;
    public ?string $statusMessage = null;

    public int $progressPercent = 0;
    public string $progressStatus = 'processing';

    public int $processedCount = 0;
    public int $totalCount = 0;
    public ?string $estimatedCompletion = null;

    public int $pollCount = 0;
    public int $maxPolls = 60;
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

        // Jika job berjalan sync atau worker sudah selesai sebelum section dibuka,
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
            'isProcessing', 'isCompleted', 'hasError', 'hasTimedOut',
            'downloadUrl', 'statusMessage',
            'progressPercent', 'progressStatus',
            'processedCount', 'totalCount', 'estimatedCompletion',
            'pollCount', 'startedAt',
        ]);

        $this->isProcessing = true;
        $this->progressStatus = 'processing';
        $this->progressPercent = 0;
        $this->processedCount = 0;
        $this->totalCount = 0;
        $this->statusMessage = __('Preparing print job...');
        $this->startedAt = now()->toDateTimeString();
        $this->showSection = true;

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
        $this->processedCount = $progress['processed_count'] ?? 0;
        $this->totalCount = $progress['total_count'] ?? 0;
        $this->statusMessage = $this->resolveStatusMessage($progress['message'] ?? $this->statusMessage);
        $this->estimatedCompletion = $this->calculateEta($progress['job_started_at'] ?? null);

        if (in_array($this->progressStatus, ['completed', 'failed'], true)) {
            $this->applyCompletion(
                $progress['download_url'] ?? null,
                $progress['message'] ?? $this->statusMessage
            );
        }
    }

    /**
     * Map pesan status job ke label yang lebih user-friendly.
     */
    private function resolveStatusMessage(?string $message): string
    {
        return match ($message) {
            'Memvalidasi data...' => __('Preparing data'),
            'Mengambil data...' => __('Fetching data'),
            'Menyiapkan label...' => __('Preparing labels'),
            'Membuat PDF...' => __('Generating PDF'),
            'File PDF Anda telah siap.' => __('Print completed successfully'),
            default => $message ?: __('Processing...'),
        };
    }

    /**
     * Hitung estimasi waktu selesai berdasarkan progress saat ini.
     */
    private function calculateEta(?string $jobStartedAt): ?string
    {
        if (! $jobStartedAt || $this->progressPercent <= 0 || $this->progressPercent >= 100) {
            return null;
        }

        try {
            $started = Carbon::parse($jobStartedAt);
        } catch (\Exception) {
            return null;
        }

        $elapsedSeconds = (float) now()->diffInSeconds($started);

        if ($elapsedSeconds <= 0) {
            return null;
        }

        $totalEstimatedSeconds = ($elapsedSeconds / $this->progressPercent) * 100;
        $remainingSeconds = max(0, (int) round($totalEstimatedSeconds - $elapsedSeconds));

        if ($remainingSeconds < 60) {
            return __('~:seconds seconds remaining', ['seconds' => $remainingSeconds]);
        }

        $minutes = (int) floor($remainingSeconds / 60);
        $seconds = $remainingSeconds % 60;

        return __('~:minutes min :seconds sec remaining', [
            'minutes' => $minutes,
            'seconds' => sprintf('%02d', $seconds),
        ]);
    }

    /**
     * Deteksi timeout agar section tidak berputar selamanya.
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
        $this->statusMessage = $this->resolveStatusMessage($message);
        $this->progressPercent = 100;
        $this->progressStatus = $downloadUrl ? 'completed' : 'failed';
        $this->isCompleted = (bool) $downloadUrl;
        $this->hasError = ! $downloadUrl;
        $this->estimatedCompletion = null;
        $this->showSection = true;

        if ($this->isCompleted) {
            $this->dispatch('print-job-finished');
        }
    }

    /**
     * Sembunyikan progress section.
     */
    public function hideProgressSection(): void
    {
        $this->showSection = false;
        $this->isProcessing = false;
        $this->isCompleted = false;
        $this->hasError = false;
        $this->hasTimedOut = false;
        $this->downloadUrl = null;
        $this->statusMessage = null;
        $this->progressPercent = 0;
        $this->progressStatus = 'processing';
        $this->processedCount = 0;
        $this->totalCount = 0;
        $this->estimatedCompletion = null;
        $this->pollCount = 0;
        $this->startedAt = null;
    }

    /**
     * Minta parent untuk mengulang proses print dengan data terakhir.
     */
    public function retry(): void
    {
        $this->hideProgressSection();
        $this->dispatch('print-job-retry');
    }
}; ?>

<div>
    @if ($showSection)
        <x-card class="mb-4 overflow-hidden" wire:transition>
            <div class="flex flex-col gap-4" @if ($isProcessing) wire:poll.2000ms="checkStatus" @endif>
                {{-- Header --}}
                <div class="flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        @if ($isProcessing)
                            <x-loading class="loading-spinner loading-sm text-primary" />
                            <span class="font-semibold text-base-content">{{ __('Printing in progress') }}</span>
                        @elseif ($isCompleted)
                            <x-icon name="o-check-circle" class="w-6 h-6 text-success shrink-0" />
                            <span class="font-semibold text-success">{{ __('Print completed successfully') }}</span>
                        @elseif ($hasError)
                            <x-icon name="o-exclamation-triangle" class="w-6 h-6 text-error shrink-0" />
                            <span class="font-semibold text-error">{{ __('Print failed') }}</span>
                        @elseif ($hasTimedOut)
                            <x-icon name="o-clock" class="w-6 h-6 text-warning shrink-0" />
                            <span class="font-semibold text-warning">{{ __('Still processing') }}</span>
                        @endif
                    </div>

                    <x-button
                        icon="o-x-mark"
                        class="btn-ghost btn-sm btn-circle"
                        wire:click="hideProgressSection"
                        aria-label="{{ __('Close') }}" />
                </div>

                {{-- Progress bar --}}
                <div class="w-full">
                    <div class="flex justify-between text-sm mb-1">
                        <span class="text-base-content/80">{{ $statusMessage }}</span>
                        <span class="font-medium text-base-content">{{ $progressPercent }}%</span>
                    </div>
                    <div class="w-full bg-base-300 rounded-full h-2.5">
                        <div
                            class="h-2.5 rounded-full transition-all duration-500
                                {{ $hasError || $hasTimedOut ? 'bg-error' : ($isCompleted ? 'bg-success' : 'bg-primary') }}"
                            style="width: {{ $progressPercent }}%"></div>
                    </div>
                </div>

                {{-- Stats --}}
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-base-content/70">
                    @if ($totalCount > 0)
                        <div class="flex items-center gap-2">
                            <x-icon name="o-document-text" class="w-4 h-4" />
                            <span>{{ $processedCount }} / {{ $totalCount }} {{ __('records processed') }}</span>
                        </div>
                    @endif

                    @if ($estimatedCompletion && $isProcessing)
                        <div class="flex items-center gap-2">
                            <x-icon name="o-clock" class="w-4 h-4" />
                            <span>{{ $estimatedCompletion }}</span>
                        </div>
                    @endif
                </div>

                {{-- Actions --}}
                <div class="flex flex-wrap items-center gap-2 pt-2">
                    @if ($downloadUrl)
                        <a href="{{ $downloadUrl }}" target="_blank" class="btn btn-success btn-sm" @click="$wire.hideProgressSection()">
                            <x-icon name="o-arrow-down-tray" class="w-4 h-4" />
                            {{ __('Download File') }}
                        </a>
                    @endif

                    @if ($hasError)
                        <x-button
                            :label="__('Retry')"
                            icon="o-arrow-path"
                            class="btn-error btn-sm"
                            wire:click="retry"
                            spinner="retry" />
                    @endif

                    @if ($hasTimedOut)
                        <x-button
                            :label="__('Check now')"
                            icon="o-magnifying-glass"
                            class="btn-primary btn-sm"
                            wire:click="checkStatus"
                            spinner="checkStatus" />
                    @endif
                </div>
            </div>
        </x-card>
    @endif
</div>
