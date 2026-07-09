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
        ->assertDispatched('print-job-started');

    $progress = Cache::store(config('app.print_progress_cache_store'))->get("print-progress:{$user->id}");

    expect($progress)
        ->toBeArray()
        ->and($progress['status'] ?? null)->toBe('completed')
        ->and($progress['download_url'] ?? null)->not->toBeEmpty();

    Notification::assertSentTo($user, PrintJobComplete::class);
});
