<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('reason_code', 30);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_before')->nullable();
            $table->unsignedInteger('quantity_after')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('corrects_movement_id')->nullable()
                ->constrained('stock_movements')->restrictOnDelete();
            $table->string('status', 30)->default('applied');
            $table->dateTime('occurred_at');
            $table->dateTime('recorded_at')->useCurrent();

            $table->index(['product_id', 'occurred_at']);
            $table->index('occurred_at');
    });

    DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check
    CHECK (quantity > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
