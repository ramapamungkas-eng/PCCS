<?php

namespace App\Console\Commands;

use App\Jobs\CleanupOldPdfFiles;
use Illuminate\Console\Command;

class CleanPrintStorage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clean-print-storage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Membersihkan file PDF lama dan file sementara Playwright.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        CleanupOldPdfFiles::dispatch();

        $this->info('Cleanup job dispatched.');

        return self::SUCCESS;
    }
}
