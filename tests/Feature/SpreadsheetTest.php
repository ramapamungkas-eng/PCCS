<?php

use App\Exports\FinishGoodTemplateExport;
use App\Exports\HpmScheduleTemplateExport;
use App\Exports\PccTemplateExport;
use App\Imports\FinishGoodImport;
use App\Support\Excel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('spreadsheet');

test('finish good template can be downloaded and re-imported', function () {
    $export = new FinishGoodTemplateExport;
    $response = Excel::download($export, 'finish_good_template.xlsx');

    expect($response->getStatusCode())->toBe(200);

    $tempPath = tempnam(sys_get_temp_dir(), 'finish_good_template_').'.xlsx';
    file_put_contents($tempPath, $response->getFile()->getContent());

    $import = new FinishGoodImport;
    Excel::import($import, $tempPath);

    expect($import->getRowCount())->toBe(2);
});

test('hpm schedule template can be downloaded', function () {
    $export = new HpmScheduleTemplateExport;
    $response = Excel::download($export, 'hpm_schedule_template.xlsx');

    expect($response->getStatusCode())->toBe(200);
});

test('pcc template can be downloaded', function () {
    $export = new PccTemplateExport;
    $response = Excel::download($export, 'pcc_template.xlsx');

    expect($response->getStatusCode())->toBe(200);
});
