<?php

namespace App\Jobs;

use App\Models\Customer\HPM\Pcc;
use App\Models\User;
use App\Notifications\PrintJobComplete;
use App\Services\PdfService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrintLabelsPCC implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 600;

    private const BARCODE_MAX_LENGTH = 250;

    private const STORAGE_DIRECTORY = 'print/labels/pccs';

    public function __construct(
        protected array $selectedIds,
        protected User $user
    ) {}

    /**
     * Unique key per user — cegah double dispatch dalam 10 menit.
     */
    public function uniqueId(): string
    {
        return 'print-pcc-'.$this->user->id;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '512M');

        $jobStartedAt = now();

        try {
            $this->setProgress('processing', 0, 'Memvalidasi data...', null, 0, 0, $jobStartedAt);

            $validIds = $this->validateAndFilterIds();
            $totalRecords = count($validIds);

            if (empty($validIds)) {
                $this->notifyAndAbort('Tidak ada data yang valid untuk dicetak.', 0, $totalRecords);

                return;
            }

            $this->setProgress('processing', 10, 'Mengambil data...', null, 0, $totalRecords, $jobStartedAt);

            $dataToPrint = $this->fetchData($validIds);

            if ($dataToPrint->isEmpty()) {
                $this->notifyAndAbort('Tidak ada data yang ditemukan sesuai kriteria.', 0, $totalRecords);

                return;
            }

            $this->setProgress('processing', 30, 'Menyiapkan label...', null, 0, $totalRecords, $jobStartedAt);

            [$labels, $skippedCount] = $this->mapLabels($dataToPrint, $totalRecords, $jobStartedAt);

            if (empty($labels)) {
                $this->notifyAndAbort('Tidak ada label valid yang bisa diproses.', $totalRecords, $totalRecords);

                return;
            }

            $this->setProgress('processing', 50, 'Membuat PDF...', null, $totalRecords, $totalRecords, $jobStartedAt);

            $storagePath = $this->generatePdf($labels);
            $this->verifyPdfFile($storagePath);

            $downloadUrl = Storage::disk('public')->url($storagePath);

            $this->setProgress('completed', 100, 'File PDF Anda telah siap.', $downloadUrl, $totalRecords, $totalRecords, $jobStartedAt);
            $this->user->notify(new PrintJobComplete($downloadUrl, 'File PDF Anda telah siap. Klik untuk mengunduh.'));

            Log::info('PrintLabelsPCC Completed', [
                'user_id' => $this->user->id,
                'download_url' => $downloadUrl,
            ]);

        } catch (Throwable $e) {
            $this->handleError($e);
            throw $e;
        }
    }

    private function validateAndFilterIds(): array
    {
        return array_values(array_filter(
            $this->selectedIds,
            fn ($item) => is_string($item) && ! empty(trim($item))
        ));
    }

    private function fetchData(array $validIds)
    {
        return Pcc::with(['schedule:id,slip_number,schedule_date,adjusted_date,schedule_time,adjusted_time'])
            ->whereIn('id', $validIds)
            ->select([
                'id', 'from', 'to', 'part_no', 'part_name', 'color_code',
                'supply_address', 'next_supply_address', 'ps_code', 'order_class',
                'prod_seq_no', 'kd_lot_no', 'ms_id', 'inventory_category',
                'ship', 'hns', 'slip_barcode', 'slip_no', 'date', 'time',
            ])
            ->get();
    }

    private function mapLabels($dataToPrint, int $totalRecords, Carbon $jobStartedAt): array
    {
        $labels = [];
        $skippedCount = 0;
        $processedCount = 0;
        $totalItems = $dataToPrint->count();
        $progressReportInterval = max(1, (int) round($totalItems / 10));

        foreach ($dataToPrint as $item) {
            $processedCount++;

            try {
                $barcodeData = $this->sanitizeBarcode($item->slip_barcode ?? '');

                if (empty($barcodeData)) {
                    $skippedCount++;

                    continue;
                }

                $labels[] = $this->buildLabelData($item, $barcodeData);

            } catch (\Exception $e) {
                $skippedCount++;
            }

            if ($processedCount % $progressReportInterval === 0 || $processedCount === $totalItems) {
                $progressInMappingPhase = (int) round(30 + (($processedCount / $totalItems) * 20));
                $this->setProgress(
                    'processing',
                    $progressInMappingPhase,
                    'Menyiapkan label...',
                    null,
                    $processedCount,
                    $totalRecords,
                    $jobStartedAt
                );
            }
        }

        return [$labels, $skippedCount];
    }

    private function sanitizeBarcode(string $barcode): string
    {
        $barcode = trim($barcode);
        $barcode = preg_replace('/[\x00-\x1F\x7F]/', '', $barcode);

        return substr($barcode, 0, self::BARCODE_MAX_LENGTH);
    }

    private function buildLabelData($item, string $barcodeData): array
    {
        return [
            'from' => $item->from ?? '',
            'to' => $item->to ?? '',
            'partNo' => $item->part_no ?? '',
            'partDesc' => $item->part_name ?? '',
            'colorCode' => $item->color_code ?? '',
            'supplyAddress' => $item->supply_address ?? '',
            'nextSupplyAddress' => $item->next_supply_address ?? '',
            'psCode' => $item->ps_code ?? '',
            'orderClass' => $item->order_class ?? '',
            'prodSeqNo' => $item->prod_seq_no ?? '',
            'kdLotNo' => $item->kd_lot_no ?? '',
            'msId' => $item->ms_id ?? '',
            'inventoryCategory' => $item->inventory_category ?? '',
            'ship' => $item->ship ?? 0,
            'hns' => $item->hns ?? '',
            'formatted_date' => $item->effective_date ?? '',
            'formatted_time' => $item->effective_time ?? '',
            'mainBarcodeData' => $barcodeData,
        ];
    }

    private function generatePdf(array $labels): string
    {
        $filename = "labels-{$this->user->id}-".now()->timestamp.'.pdf';
        $storagePath = self::STORAGE_DIRECTORY."/{$filename}";

        Storage::disk('public')->makeDirectory(self::STORAGE_DIRECTORY);

        $fullPath = Storage::disk('public')->path($storagePath);

        $html = view('components.ui.labels.pcc', ['labels' => $labels])->render();

        $pdf = app(PdfService::class);

        $pdf->generate($html, [
            'outputPath' => $fullPath,
            'format' => 'A4',
            'margin' => ['top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0'],
            'timeout' => 300,
        ]);

        return $storagePath;
    }

    private function verifyPdfFile(string $storagePath): void
    {
        if (! Storage::disk('public')->exists($storagePath)) {
            throw new \Exception('PDF creation failed: File not found.');
        }

        if (Storage::disk('public')->size($storagePath) === 0) {
            throw new \Exception('PDF creation failed: File size is 0 bytes.');
        }
    }

    private function notifyAndAbort(string $message, int $processedCount = 0, int $totalCount = 0): void
    {
        Log::warning("PrintLabelsPCC Aborted: {$message}", ['user_id' => $this->user->id]);
        $this->setProgress('failed', 100, $message, null, $processedCount, $totalCount, now());
        $this->user->notify(new PrintJobComplete(null, $message));
    }

    private function handleError(Throwable $e): void
    {
        $msg = $e->getMessage();

        $isChromeError = str_contains($msg, 'Browser')
            || str_contains($msg, 'Chrome')
            || str_contains($msg, 'Puppeteer')
            || str_contains($msg, 'Browsershot');

        Log::error('PrintLabelsPCC Failed', [
            'user' => $this->user->id,
            'error' => $msg,
            'trace' => $e->getTraceAsString(),
        ]);

        $userMessage = $isChromeError
            ? 'Terjadi kesalahan pada sistem PDF Generator. Silakan hubungi IT.'
            : 'Gagal membuat PDF. Silakan coba lagi nanti.';

        $this->setProgress('failed', 100, $userMessage, null, 0, 0, now());
        $this->user->notify(new PrintJobComplete(null, $userMessage));
    }

    private function setProgress(
        string $status,
        int $progress,
        string $message,
        ?string $downloadUrl = null,
        int $processedCount = 0,
        int $totalCount = 0,
        ?Carbon $jobStartedAt = null,
    ): void {
        // Gunakan store yang persisten agar progress bisa dibaca antar request.
        Cache::store(config('app.print_progress_cache_store'))->put(
            "print-progress:{$this->user->id}",
            [
                'status' => $status,
                'progress' => $progress,
                'message' => $message,
                'download_url' => $downloadUrl,
                'processed_count' => $processedCount,
                'total_count' => $totalCount,
                'job_started_at' => $jobStartedAt?->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ],
            now()->addMinutes(10)
        );
    }
}
