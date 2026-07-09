<?php

use App\Models\Customer\HPM\Pcc;
use App\Models\Master\FinishGood;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->create();
    $this->user->assignRole('ppic');
});

test('pccs list renders with records', function () {
    $pccs = Pcc::factory()->count(3)->create();

    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.pccs')
        ->assertOk()
        ->assertSee($pccs->first()->part_no);
});

test('pcc detail modal shows finish good data when available', function () {
    $finishGood = FinishGood::factory()->create();
    $pcc = Pcc::factory()->create(['part_no' => $finishGood->alias]);

    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.pccs')
        ->call('viewDetails', $pcc->id)
        ->assertSet('detailsModal', true)
        ->assertSee($finishGood->part_number)
        ->assertSee($finishGood->part_name);
});

test('pcc detail modal falls back to part_no without finish good', function () {
    $pcc = Pcc::factory()->create();

    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.pccs')
        ->call('viewDetails', $pcc->id)
        ->assertSet('detailsModal', true)
        ->assertSee($pcc->part_no)
        ->assertSee(__('No FinishGood data'));
});

test('pcc detail modal handles missing record gracefully', function () {
    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.pccs')
        ->call('viewDetails', 'non-existent-id')
        ->assertSet('detailsModal', false)
        ->assertOk();
});
