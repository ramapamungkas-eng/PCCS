<?php

use App\Jobs\CleanupOldPdfFiles;
use App\Services\PlaywrightPdfService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new CleanupOldPdfFiles)->daily();

Schedule::call(function () {
    app(PlaywrightPdfService::class)->cleanTemporaryFiles(1);
})->everyFifteenMinutes();
