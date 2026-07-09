<?php

namespace Database\Factories\Master;

use App\Models\Master\Customer;
use App\Models\Master\FinishGood;
use Illuminate\Database\Eloquent\Factories\Factory;

class FinishGoodFactory extends Factory
{
    protected $model = FinishGood::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'part_number' => $this->faker->unique()->bothify('PART-####'),
            'part_name' => $this->faker->words(3, true),
            'alias' => $this->faker->unique()->bothify('ALIAS-####'),
            'model' => $this->faker->word(),
            'variant' => $this->faker->word(),
            'stock' => 0,
            'wh_address' => $this->faker->randomElement(['A-01', 'B-02', 'C-03']),
            'type' => $this->faker->randomElement(['ASSY', 'DIRECT']),
            'is_active' => true,
        ];
    }
}
