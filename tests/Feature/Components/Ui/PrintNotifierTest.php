<?php

use App\Models\User;
use App\Notifications\PrintJobComplete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::store(config('app.print_progress_cache_store'))->flush();
});

it('shows completed state when a finished job exists before the section opens', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Cache::store(config('app.print_progress_cache_store'))->put("print-progress:{$user->id}", [
        'status' => 'completed',
        'progress' => 100,
        'message' => 'File PDF Anda telah siap.',
        'download_url' => 'http://localhost/storage/print/labels/pccs/labels-test.pdf',
        'processed_count' => 10,
        'total_count' => 10,
        'job_started_at' => now()->subMinute()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], now()->addMinutes(10));

    Volt::test('components.ui.print-notifier')
        ->call('startProcessing')
        ->assertSet('isProcessing', false)
        ->assertSet('isCompleted', true)
        ->assertSet('downloadUrl', 'http://localhost/storage/print/labels/pccs/labels-test.pdf')
        ->assertSet('showSection', true);

    // Make sure the existing notification is not blindly deleted.
    $user->notify(new PrintJobComplete('http://localhost/storage/print/labels/pccs/labels-test.pdf', 'Ready'));

    Volt::test('components.ui.print-notifier')
        ->call('startProcessing')
        ->assertSet('isProcessing', false);
});

it('times out when no completion data arrives', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Volt::test('components.ui.print-notifier')
        ->call('startProcessing')
        ->assertSet('isProcessing', true);

    for ($i = 0; $i < 65; $i++) {
        $component->call('checkStatus');
    }

    $component
        ->assertSet('hasTimedOut', true)
        ->assertSet('isProcessing', false);
});

it('displays record counts and eta while processing', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Cache::store(config('app.print_progress_cache_store'))->put("print-progress:{$user->id}", [
        'status' => 'processing',
        'progress' => 50,
        'message' => 'Menyiapkan label...',
        'download_url' => null,
        'processed_count' => 25,
        'total_count' => 50,
        'job_started_at' => now()->subSeconds(30)->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], now()->addMinutes(10));

    Volt::test('components.ui.print-notifier')
        ->call('startProcessing')
        ->assertSet('processedCount', 25)
        ->assertSet('totalCount', 50)
        ->assertSee('25 / 50 records processed')
        ->assertSee('Preparing labels')
        ->assertSee('50%');
});

it('shows error state with retry option when job fails', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Cache::store(config('app.print_progress_cache_store'))->put("print-progress:{$user->id}", [
        'status' => 'failed',
        'progress' => 100,
        'message' => 'Gagal membuat PDF. Silakan coba lagi nanti.',
        'download_url' => null,
        'processed_count' => 0,
        'total_count' => 0,
        'job_started_at' => now()->subMinute()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], now()->addMinutes(10));

    Volt::test('components.ui.print-notifier')
        ->call('startProcessing')
        ->assertSet('hasError', true)
        ->assertSet('isCompleted', false)
        ->assertSee('Print failed')
        ->assertSee('Retry');
});

it('can hide the progress section', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Volt::test('components.ui.print-notifier')
        ->call('startProcessing')
        ->assertSet('showSection', true)
        ->call('hideProgressSection')
        ->assertSet('showSection', false)
        ->assertSet('progressPercent', 0);
});
