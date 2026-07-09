<?php

use App\Models\Customer\HPM\Pcc;
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

test('ppic dashboard renders and shows stats', function () {
    Pcc::factory()->count(3)->create(['date' => now()]);

    Volt::actingAs($this->user);

    Volt::test('ppic.hpm.dashboard')
        ->assertOk()
        ->assertSee(__('HPM Dashboard'))
        ->assertSee(__('Planned (items)'));
});
