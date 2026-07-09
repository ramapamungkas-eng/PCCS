<?php

use App\Services\PlaywrightPdfService;

it('renders html to a non-empty pdf', function () {
    $service = app(PlaywrightPdfService::class);

    $pdfPath = $service->generate('<html><body><h1>PCCS Label Test</h1></body></html>', [
        'format' => 'A4',
        'margin' => ['top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0'],
        'printBackground' => true,
        'args' => [
            '--no-sandbox',
            '--disable-setuid-sandbox',
        ],
    ]);

    expect($pdfPath)
        ->toBeFile()
        ->and(filesize($pdfPath))
        ->toBeGreaterThan(0);

    unlink($pdfPath);
});
