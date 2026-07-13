<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PlaywrightPdfService;
use Illuminate\Console\Command;

final class PccsPlaywrightDiagnose extends Command
{
    protected $signature = 'pccs:playwright-diagnose';

    protected $description = 'Diagnose Playwright/Chromium setup for PDF generation';

    public function handle(PlaywrightPdfService $service): int
    {
        $this->info('=== Playwright / Chromium Diagnostics ===');
        $this->newLine();

        $result = $service->diagnose();

        $this->info('Executable: '.($result['executable'] ?? '(not found)'));

        if ($result['ok']) {
            $this->info('Status: OK');
            $this->info('Version: '.$result['version']);
        } else {
            $this->error('Status: NOT WORKING');
        }

        $this->newLine();
        $this->info('Diagnostics:');

        foreach ($result['diagnostics'] as $line) {
            $this->line("  • {$line}");
        }

        $this->newLine();

        if (! $result['ok']) {
            $this->warn('Suggested fix:');
            $this->line('  1. sudo npx playwright install-deps chromium');
            $this->line('  2. npx playwright install chromium');
            $this->line('  3. Set PLAYWRIGHT_CHROMIUM_PATH in .env');
            $this->line('  4. Restart queue worker');
            $this->newLine();

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
