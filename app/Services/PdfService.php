<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;

final class PdfService
{
    /**
     * Render HTML to PDF using Spatie/LaravelPdf with Browsershot driver.
     *
     * @param  string  $html  Raw HTML to render
     * @param  array<string, mixed>  $options  PDF options (format, margin, etc.)
     * @return string Absolute path to the generated PDF
     */
    public function generate(string $html, array $options = []): string
    {
        $outputPath = ($options['outputPath'] ?? null)
            ?: throw new RuntimeException('outputPath is required');

        $format = $options['format'] ?? 'A4';

        $margins = $options['margin'] ?? ['top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0'];

        $timeout = (int) ($options['timeout'] ?? 300);

        Pdf::html($html)
            ->format($format)
            ->margins(
                (float) $margins['top'],
                (float) $margins['right'],
                (float) $margins['bottom'],
                (float) $margins['left'],
            )
            ->withBrowsershot(function (Browsershot $browsershot) use ($options, $timeout) {
                $browsershot
                    ->noSandbox()
                    ->setOption('args', [
                        '--no-sandbox',
                        '--disable-setuid-sandbox',
                        '--disable-web-security',
                        '--disable-dev-shm-usage',
                        '--disable-gpu',
                        '--disable-software-rasterizer',
                        '--disable-features=Crashpad',
                    ])
                    ->timeout($timeout * 1000)
                    ->waitUntilNetworkIdle();

                $chromePath = $options['executablePath']
                    ?? config('laravel-pdf.browsershot.chrome_path')
                    ?? null;

                if ($chromePath) {
                    $browsershot->setChromePath($chromePath);
                }
            })
            ->save($outputPath);

        return $outputPath;
    }
}
