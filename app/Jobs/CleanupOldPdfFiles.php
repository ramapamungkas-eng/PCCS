<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupOldPdfFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tentukan folder target.
     * Sesuaikan dengan folder penyimpanan PDF di script sebelumnya.
     */
    protected string $directory = 'print/labels/pccs';

    /**
     * Tentukan disk storage (biasanya 'public' atau 'local').
     */
    protected string $disk = 'public';

    public function handle(): void
    {
        Log::info("Starting cleanup of old PDF files in {$this->directory}...");

        $deletedCount = $this->cleanPdfDirectory();

        Log::info('Cleanup complete. Total PDF files deleted: '.$deletedCount);
    }

    /**
     * Hapus file PDF lama dari public storage.
     */
    private function cleanPdfDirectory(): int
    {
        $storage = Storage::disk($this->disk);

        if (! $storage->exists($this->directory)) {
            Log::info("Directory {$this->directory} does not exist. Skipping cleanup.");

            return 0;
        }

        $files = $storage->files($this->directory);
        $thresholdDate = Carbon::now()->subWeek()->timestamp;
        $deletedCount = 0;

        foreach ($files as $file) {
            if (! str_ends_with(strtolower($file), '.pdf')) {
                continue;
            }

            try {
                $lastModified = $storage->lastModified($file);

                if ($lastModified < $thresholdDate) {
                    $storage->delete($file);
                    $deletedCount++;
                }
            } catch (\Exception $e) {
                Log::warning("Failed to delete file: {$file}. Error: ".$e->getMessage());
            }
        }

        return $deletedCount;
    }
}
