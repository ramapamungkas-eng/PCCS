<?php

use App\Models\Customer\HPM\Pcc;
use App\Models\User;
use App\Notifications\PrintJobComplete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::store(config('app.print_progress_cache_store'))->flush();
});

it('dispatches print job and notifier sees completion for many labels', function () {
    Notification::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $records = Pcc::factory()->count(20)->create([
        'slip_barcode' => fn () => 'TESTBARCODE'.fake()->unique()->numberBetween(1000, 9999),
    ]);

    $ids = $records->pluck('id')->toArray();

    Volt::test('ppic.hpm.pccs')
        ->set('selectedIds', $ids)
        ->call('bulkPrint')
        ->assertDispatched('print-job-started')
        ->assertSet('isPrinting', true);

    $progress = Cache::store(config('app.print_progress_cache_store'))->get("print-progress:{$user->id}");

    expect($progress)
        ->toBeArray()
        ->and($progress['status'] ?? null)->toBe('completed')
        ->and($progress['download_url'] ?? null)->not->toBeEmpty()
        ->and($progress['processed_count'] ?? null)->toBe(20)
        ->and($progress['total_count'] ?? null)->toBe(20)
        ->and($progress['job_started_at'] ?? null)->not->toBeEmpty();

    Notification::assertSentTo($user, PrintJobComplete::class);
});

it('stores last print ids and disables print button while printing', function () {
    Notification::fake();

    $user = User::factory()->create();
    $this->actingAs($user);

    $records = Pcc::factory()->count(5)->create([
        'slip_barcode' => fn () => 'TESTBARCODE'.fake()->unique()->numberBetween(1000, 9999),
    ]);

    $ids = $records->pluck('id')->toArray();

    Volt::test('ppic.hpm.pccs')
        ->set('selectedIds', $ids)
        ->call('bulkPrint')
        ->assertSet('selectedIds', [])
        ->assertSet('lastPrintIds', $ids)
        ->assertSet('isPrinting', true);
});

it('finishes printing when notifier reports completion', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('ppic.hpm.pccs')
        ->set('isPrinting', true)
        ->call('finishPrinting')
        ->assertSet('isPrinting', false);
});
