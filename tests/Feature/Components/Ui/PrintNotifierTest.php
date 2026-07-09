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

it('shows completed state when a finished job exists before the modal opens', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Cache::store(config('app.print_progress_cache_store'))->put("print-progress:{$user->id}", [
        'status' => 'completed',
        'progress' => 100,
        'message' => 'File PDF Anda telah siap.',
        'download_url' => 'http://localhost/storage/print/labels/pccs/labels-test.pdf',
        'updated_at' => now()->toDateTimeString(),
    ], now()->addMinutes(10));

    Volt::test('components.ui.print-notifier')
        ->call('startProcessing')
        ->assertSet('isProcessing', false)
        ->assertSet('downloadUrl', 'http://localhost/storage/print/labels/pccs/labels-test.pdf')
        ->assertSet('showModal', true);

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
