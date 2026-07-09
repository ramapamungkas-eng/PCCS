<?php

namespace Database\Factories\Customer\HPM;

use App\Models\Customer\HPM\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    public function definition(): array
    {
        return [
            'slip_number' => $this->faker->unique()->bothify('SLIP-####'),
            'schedule_date' => now(),
            'adjusted_date' => null,
            'schedule_time' => '08:00:00',
            'adjusted_time' => null,
            'delivery_quantity' => $this->faker->numberBetween(1, 100),
            'adjustment_quantity' => 0,
        ];
    }
}
