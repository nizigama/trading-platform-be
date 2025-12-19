<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Trade>
 */
class TradeFactory extends Factory
{
    protected $model = Trade::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->randomFloat(8, 100, 50000);
        $amount = fake()->randomFloat(8, 0.001, 10);
        $commission = $price * $amount * 0.015;

        return [
            'order_id' => Order::factory(),
            'buyer_id' => User::factory(),
            'seller_id' => User::factory(),
            'symbol_id' => Symbol::factory(),
            'price' => $price,
            'amount' => $amount,
            'commission' => $commission,
        ];
    }
}

