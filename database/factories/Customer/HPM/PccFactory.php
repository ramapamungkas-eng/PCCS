<?php

namespace Database\Factories\Customer\HPM;

use App\Models\Customer\HPM\Pcc;
use Illuminate\Database\Eloquent\Factories\Factory;

class PccFactory extends Factory
{
    protected $model = Pcc::class;

    public function definition(): array
    {
        return [
            'from' => $this->faker->word(),
            'to' => $this->faker->word(),
            'supply_address' => $this->faker->word(),
            'next_supply_address' => $this->faker->word(),
            'ms_id' => $this->faker->bothify('MS-####'),
            'inventory_category' => $this->faker->word(),
            'part_no' => $this->faker->unique()->bothify('PART-####'),
            'part_name' => $this->faker->words(3, true),
            'color_code' => $this->faker->hexColor(),
            'ps_code' => $this->faker->bothify('PS-####'),
            'order_class' => $this->faker->word(),
            'prod_seq_no' => $this->faker->bothify('SEQ-####'),
            'kd_lot_no' => $this->faker->bothify('LOT-####'),
            'ship' => $this->faker->numberBetween(1, 100),
            'slip_no' => $this->faker->bothify('SLIP-####'),
            'slip_barcode' => $this->faker->unique()->bothify('BC-########'),
            'printed' => false,
            'date' => now(),
            'time' => '08:00:00',
            'hns' => $this->faker->word(),
        ];
    }
}
