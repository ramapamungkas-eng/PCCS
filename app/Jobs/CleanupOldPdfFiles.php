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

        $storage = Storage::disk($this->disk);

        // 1. Cek apakah direktori ada
        if (!$storage->exists($this->directory)) {
            Log::info("Directory {$this->directory} does not exist. Skipping cleanup.");
            return;
        }

        // 2. Ambil semua file dalam direktori tersebut
        $files = $storage->files($this->directory);
        
        // Tentukan batas waktu (1 minggu yang lalu)
        $thresholdDate = Carbon::now()->subWeek()->timestamp;
        
        $deletedCount = 0;

        foreach ($files as $file) {
            // Pastikan kita hanya menghapus file PDF (safety check)
            if (!str_ends_with(strtolower($file), '.pdf')) {
                continue;
            }

            try {
                // 3. Cek kapan terakhir file dimodifikasi
                $lastModified = $storage->lastModified($file);

                // 4. Jika file lebih tua dari batas waktu, hapus
                if ($lastModified < $thresholdDate) {
                    $storage->delete($file);
                    $deletedCount++;
                }
            } catch (\Exception $e) {
                // Log error jika gagal menghapus file tertentu tapi jangan stop proses
                Log::warning("Failed to delete file: {$file}. Error: " . $e->getMessage());
            }
        }

        Log::info("Cleanup complete. Total files deleted: {$deletedCount}");
    }
}