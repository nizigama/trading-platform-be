<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('symbol_id')->constrained()->restrictOnDelete();
            $table->tinyInteger('side'); // 1 = buy, 2 = sell
            $table->decimal('price', 36, 18);
            $table->decimal('amount', 36, 18);
            $table->tinyInteger('status')->default(1); // 1 = open, 2 = filled, 3 = cancelled
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

