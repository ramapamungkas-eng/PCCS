<?php

namespace App\Jobs;

use App\Models\Customer\HPM\Pcc;
use App\Models\User;
use App\Notifications\PrintJobComplete;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Browsershot\Browsershot;
use Throwable;

class PrintLabelsPCC implements ShouldQueue, ShouldBeUnique
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
        return 'print-pcc-' . $this->user->id;
    }

    public function handle(): void
    {
        ini_set('memory_limit', '512M');

        try {
            $validIds = $this->validateAndFilterIds();

            if (empty($validIds)) {
                $this->notifyAndAbort('Tidak ada data yang valid untuk dicetak.');
                return;
            }

            $dataToPrint = $this->fetchData($validIds);

            if ($dataToPrint->isEmpty()) {
                $this->notifyAndAbort('Tidak ada data yang ditemukan sesuai kriteria.');
                return;
            }

            [$labels, $skippedCount] = $this->mapLabels($dataToPrint);

            if (empty($labels)) {
                $this->notifyAndAbort('Tidak ada label valid yang bisa diproses.');
                return;
            }

            $storagePath = $this->generatePdf($labels);
            $this->verifyPdfFile($storagePath);

            $downloadUrl = Storage::disk('public')->url($storagePath);
            $this->user->notify(new PrintJobComplete($downloadUrl, 'File PDF Anda telah siap. Klik untuk mengunduh.'));

        } catch (Throwable $e) {
            $this->handleError($e);
            throw $e;
        }
    }

    private function validateAndFilterIds(): array
    {
        return array_values(array_filter(
            $this->selectedIds,
            fn($item) => is_string($item) && !empty(trim($item))
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
                'ship', 'hns', 'slip_barcode', 'slip_no', 'date', 'time'
            ])
            ->get();
    }

    private function mapLabels($dataToPrint): array
    {
        $labels = [];
        $skippedCount = 0;

        foreach ($dataToPrint as $item) {
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
            'from'              => $item->from ?? '',
            'to'                => $item->to ?? '',
            'partNo'            => $item->part_no ?? '',
            'partDesc'          => $item->part_name ?? '',
            'colorCode'         => $item->color_code ?? '',
            'supplyAddress'     => $item->supply_address ?? '',
            'nextSupplyAddress' => $item->next_supply_address ?? '',
            'psCode'            => $item->ps_code ?? '',
            'orderClass'        => $item->order_class ?? '',
            'prodSeqNo'         => $item->prod_seq_no ?? '',
            'kdLotNo'           => $item->kd_lot_no ?? '',
            'msId'              => $item->ms_id ?? '',
            'inventoryCategory' => $item->inventory_category ?? '',
            'ship'              => $item->ship ?? 0,
            'hns'               => $item->hns ?? '',
            'formatted_date'    => $item->effective_date ?? '',
            'formatted_time'    => $item->effective_time ?? '',
            'mainBarcodeData'   => $barcodeData,
        ];
    }

    private function generatePdf(array $labels): string
    {
        $filename    = "labels-{$this->user->id}-" . now()->timestamp . '.pdf';
        $storagePath = self::STORAGE_DIRECTORY . "/{$filename}";

        Storage::disk('public')->makeDirectory(self::STORAGE_DIRECTORY);

        $fullPath   = Storage::disk('public')->path($storagePath);
        $chromePath = $this->getChromePath();

        Pdf::view('components.ui.labels.pcc', ['labels' => $labels])
            ->withBrowsershot(function (Browsershot $browsershot) use ($chromePath) {

                if ($chromePath) {
                    $browsershot->setChromePath($chromePath);
                }

                $browsershot
                    ->noSandbox()
                    ->addChromiumArguments([
                        'disable-setuid-sandbox',
                        'disable-web-security',
                        'disable-dev-shm-usage',
                        'disable-gpu',
                        'disable-software-rasterizer',
                        'disable-breakpad',
                        'mute-audio',
                        'font-render-hinting=none',
                    ])
                    ->format('A4')
                    ->margins(0, 0, 0, 0, 'mm')
                    ->showBackground()
                    ->waitUntilNetworkIdle(false) // networkidle2 — lebih toleran, kurangi risiko timeout
                    ->timeout(300);
            })
            ->save($fullPath);

        return $storagePath;
    }

    private function getChromePath(): ?string
    {
        // Pakai config() bukan env()
        $path = config('app.browsershot_chrome_path');
        if ($path && is_executable($path)) {
            return $path;
        }

        $commonPaths = [
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/chrome',
            '/snap/bin/chromium',
        ];

        foreach ($commonPaths as $p) {
            if (file_exists($p) && is_executable($p)) {
                return $p;
            }
        }

        return null;
    }

    private function verifyPdfFile(string $storagePath): void
    {
        if (!Storage::disk('public')->exists($storagePath)) {
            throw new \Exception('PDF creation failed: File not found.');
        }

        if (Storage::disk('public')->size($storagePath) === 0) {
            throw new \Exception('PDF creation failed: File size is 0 bytes.');
        }
    }

    private function notifyAndAbort(string $message): void
    {
        Log::warning("PrintLabelsPCC Aborted: {$message}", ['user_id' => $this->user->id]);
        $this->user->notify(new PrintJobComplete(null, $message));
    }

    private function handleError(Throwable $e): void
    {
        $msg = $e->getMessage();

        $isChromeError = str_contains($msg, 'Browser')
            || str_contains($msg, 'Chrome')
            || str_contains($msg, 'Puppeteer')
            || str_contains($msg, 'Could not start Chrome');

        Log::error('PrintLabelsPCC Failed', [
            'user'  => $this->user->id,
            'error' => $msg,
            'trace' => $e->getTraceAsString(),
        ]);

        $userMessage = $isChromeError
            ? 'Terjadi kesalahan pada sistem PDF Generator. Silakan hubungi IT.'
            : 'Gagal membuat PDF. Silakan coba lagi nanti.';

        $this->user->notify(new PrintJobComplete(null, $userMessage));
    }
}