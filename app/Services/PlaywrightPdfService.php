<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

final class PlaywrightPdfService
{
    /**
     * Render HTML to PDF using Playwright.
     *
     * @param  string  $html  Raw HTML to render
     * @param  array<string, mixed>  $options  PDF options (format, margin, printBackground, args, etc.)
     * @return string Absolute path to the generated PDF
     */
    public function generate(string $html, array $options = []): string
    {
        $temporaryDirectory = storage_path('app/playwright-pdf');

        if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0755, true) && ! is_dir($temporaryDirectory)) {
            throw new RuntimeException("Failed to create temporary directory: {$temporaryDirectory}");
        }

        $id = Str::uuid()->toString();
        $htmlPath = $temporaryDirectory."/playwright_pdf_html_{$id}.html";
        $outputPath = $temporaryDirectory."/playwright_pdf_output_{$id}.pdf";

        file_put_contents($htmlPath, $html);

        $projectRoot = base_path();
        $nodeScript = $projectRoot.'/scripts/render-pdf.cjs';

        if (! file_exists($nodeScript)) {
            @unlink($htmlPath);
            throw new RuntimeException("Playwright PDF script not found: {$nodeScript}");
        }

        $options['executablePath'] = filled($options['executablePath'] ?? null)
            ? $options['executablePath']
            : (filled(config('app.playwright_chromium_path'))
                ? config('app.playwright_chromium_path')
                : $this->detectSystemChromium());

        $process = new Process([
            'node',
            $nodeScript,
            $htmlPath,
            $outputPath,
            json_encode($options),
        ], $projectRoot);

        $process->setTimeout(($options['timeout'] ?? 300) + 30);

        try {
            $process->run();
        } finally {
            @unlink($htmlPath);
        }

        if (! $process->isSuccessful()) {
            @unlink($outputPath);

            Log::error('Playwright PDF generation failed', [
                'command' => $process->getCommandLine(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException(
                'PDF generation failed: '.$process->getErrorOutput()
            );
        }

        if (! file_exists($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            throw new RuntimeException('PDF generation failed: output file is empty.');
        }

        return $outputPath;
    }

    /**
     * Hapus file sementara yang tertinggal di direktori Playwright.
     *
     * @param  int  $maxAgeHours  Usia file maksimum sebelum dihapus
     */
    public function cleanTemporaryFiles(int $maxAgeHours = 24): void
    {
        $temporaryDirectory = storage_path('app/playwright-pdf');

        if (! is_dir($temporaryDirectory)) {
            return;
        }

        $threshold = now()->subHours($maxAgeHours)->getTimestamp();

        foreach (new \DirectoryIterator($temporaryDirectory) as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            if ($file->getMTime() < $threshold) {
                @unlink($file->getPathname());
            }
        }
    }

    private function detectSystemChromium(): ?string
    {
        $commonPaths = [
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chrome',
            '/snap/bin/chromium',
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
