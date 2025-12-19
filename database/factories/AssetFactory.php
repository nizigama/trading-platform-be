<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'symbol_id' => Symbol::factory(),
            'amount' => fake()->randomFloat(8, 0, 100),
            'locked_amount' => fake()->randomFloat(8, 0, 10),
        ];
    }
}

