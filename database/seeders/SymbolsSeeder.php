<?php

namespace Database\Seeders;

use App\Models\Symbol;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SymbolsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Symbol::updateOrCreate(['name' => 'BTC']);
        Symbol::updateOrCreate(['name' => 'ETH']);
    }
}
