<?php

namespace Database\Factories;

use App\Models\Symbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Symbol>
 */
class SymbolFactory extends Factory
{
    protected $model = Symbol::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['BTC', 'ETH', 'SOL', 'DOGE', 'XRP', 'ADA', 'MATIC', 'DOT', 'LINK', 'AVAX']),
        ];
    }
}

