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

        $executablePath = filled($options['executablePath'] ?? null)
            ? $options['executablePath']
            : (filled(config('app.playwright_chromium_path'))
                ? config('app.playwright_chromium_path')
                : $this->detectSystemChromium());

        if (! $executablePath || ! file_exists($executablePath)) {
            @unlink($htmlPath);
            throw new RuntimeException(
                'Chromium browser not found. Please install Playwright on this server: '.
                'run "npx playwright install chromium" in your project directory, '.
                'then "npx playwright install-deps chromium" (as root) to install system dependencies.'
            );
        }

        $options['executablePath'] = $executablePath;

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

            $errorOutput = $process->getErrorOutput();

            Log::error('Playwright PDF generation failed', [
                'command' => $process->getCommandLine(),
                'output' => $process->getOutput(),
                'error' => $errorOutput,
            ]);

            $message = 'PDF generation failed: '.$errorOutput;

            if (str_contains($errorOutput, 'SIGTRAP')
                || str_contains($errorOutput, 'Target page, context or browser has been closed')
                || str_contains($errorOutput, 'crashpad')) {

                $message .= "\n\nChromium crashed on startup (SIGTRAP).\n\n";

                $appArmorProfiles = $this->detectAppArmorChromeProfiles();
                if ($appArmorProfiles !== []) {
                    $message .= "AppArmor is actively confining Chromium:\n".
                        '  '.implode(', ', $appArmorProfiles)."\n\n".
                        "Fix on the VPS:\n".
                        "  sudo apt install -y apparmor-utils\n".
                        '  sudo aa-disable '.implode(' ', $appArmorProfiles)."\n\n".
                        "If that doesn't help, also check for missing system libs:\n".
                        "  npx playwright install-deps chromium (as root)\n\n".
                        'Then restart your queue worker.';
                } else {
                    $message .= "This usually means missing system libraries.\n".
                        "Fix: run 'npx playwright install-deps chromium' (as root) on your VPS, ".
                        'then restart your queue worker.';
                }
            }

            throw new RuntimeException($message);
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

    /**
     * Diagnostic check: verify the Chromium binary can be launched.
     *
     * @return array{ok: bool, executable: ?string, version: ?string, diagnostics: string[]}
     */
    public function diagnose(): array
    {
        $diagnostics = [];
        $executable = filled(config('app.playwright_chromium_path'))
            ? config('app.playwright_chromium_path')
            : $this->detectSystemChromium();

        if (! $executable) {
            return [
                'ok' => false,
                'executable' => null,
                'version' => null,
                'diagnostics' => [
                    'No Chromium binary found. Run: npx playwright install chromium && npx playwright install-deps chromium',
                ],
            ];
        }

        $diagnostics[] = "Found Chromium at: {$executable}";

        if (! is_executable($executable)) {
            $diagnostics[] = "WARNING: Binary is not executable: {$executable}";

            return [
                'ok' => false,
                'executable' => $executable,
                'version' => null,
                'diagnostics' => $diagnostics,
            ];
        }

        $process = new Process([$executable, '--version']);
        $process->run();

        if ($process->isSuccessful()) {
            $version = trim($process->getOutput());
            $diagnostics[] = "Chromium version: {$version}";
        } else {
            $stderr = $process->getErrorOutput();
            $diagnostics[] = "Failed to run --version: {$stderr}";

            if (str_contains($stderr, 'SIGTRAP') || str_contains($stderr, 'error while loading shared libraries')) {
                $diagnostics[] = 'Missing system libraries — run: npx playwright install-deps chromium (as root)';
            }
        }

        $appArmorProfiles = $this->detectAppArmorChromeProfiles();
        if ($appArmorProfiles !== []) {
            $diagnostics[] = count($appArmorProfiles).' AppArmor profile(s) may block Chromium:';
            foreach ($appArmorProfiles as $profile) {
                $diagnostics[] = "  - {$profile}";
            }

            $diagnostics[] = 'Fix: sudo aa-disable '.implode(' ', $appArmorProfiles).' (install apparmor-utils first)';
        }

        return [
            'ok' => $process->isSuccessful() && $appArmorProfiles === [],
            'executable' => $executable,
            'version' => $process->isSuccessful() ? trim($process->getOutput()) : null,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @return string[]
     */
    private function detectAppArmorChromeProfiles(): array
    {
        if (! is_executable('/usr/sbin/aa-status')) {
            return [];
        }

        $process = new Process(['/usr/sbin/aa-status', '--json']);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);
        $profiles = $data['profiles'] ?? [];

        $profiles = match (true) {
            is_array($profiles)
                && count($profiles) > 0
                && array_is_list($profiles) === false => array_keys($profiles),
            is_array($profiles) => $profiles,
            default => [],
        };

        $chromeProfiles = [];

        foreach ($profiles as $profile) {
            if (str_contains($profile, 'chrome') || str_contains($profile, 'chromium')) {
                // Only include enforcement-mode profiles
                $mode = $data['profiles'][$profile] ?? null;

                if ($mode === 'enforce' || $mode === null) {
                    $chromeProfiles[] = $profile;
                }
            }
        }

        return $chromeProfiles;
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

        return $this->findPlaywrightCacheChromium() ?? $this->findHomeCacheChromium();
    }

    private function findPlaywrightCacheChromium(): ?string
    {
        $cacheDir = getenv('HOME') ?: null;

        if (! $cacheDir) {
            $userInfo = function_exists('posix_getpwuid')
                ? posix_getpwuid(function_exists('posix_geteuid') ? posix_geteuid() : 0)
                : null;
            $cacheDir = $userInfo['dir'] ?? null;
        }

        if ($cacheDir) {
            $found = $this->scanPlaywrightCache("{$cacheDir}/.cache/ms-playwright");
            if ($found) {
                return $found;
            }
        }

        $found = $this->scanPlaywrightCache('/var/www/.cache/ms-playwright');

        return $found ?: $this->scanPlaywrightCache('/root/.cache/ms-playwright');
    }

    private function findHomeCacheChromium(): ?string
    {
        $homes = ['/home', '/var/www', '/root'];

        foreach ($homes as $homeBase) {
            if (! is_dir($homeBase)) {
                continue;
            }

            foreach (scandir($homeBase) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $cachePath = "{$homeBase}/{$entry}/.cache/ms-playwright";
                $found = $this->scanPlaywrightCache($cachePath);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function scanPlaywrightCache(string $cacheDir): ?string
    {
        if (! is_dir($cacheDir)) {
            return null;
        }

        $entries = scandir($cacheDir) ?: [];

        rsort($entries);

        foreach ($entries as $entry) {
            if (! str_starts_with($entry, 'chromium-')) {
                continue;
            }

            $candidate = "{$cacheDir}/{$entry}/chrome-linux64/chrome";

            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
