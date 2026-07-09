<?php

use App\Models\Customer\HPM\Schedule;
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

test('schedules list renders with records', function () {
    $schedules = Schedule::factory()->count(3)->create();

    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.schedules')
        ->assertOk()
        ->assertSee($schedules->first()->slip_number);
});

test('schedule detail modal opens for existing record', function () {
    $schedule = Schedule::factory()->create();

    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.schedules')
        ->call('viewDetails', $schedule->id)
        ->assertSet('detailsModal', true)
        ->assertSee($schedule->slip_number);
});

test('schedule detail modal handles missing record gracefully', function () {
    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.schedules')
        ->call('viewDetails', 'non-existent-id')
        ->assertSet('detailsModal', false)
        ->assertOk();
});
