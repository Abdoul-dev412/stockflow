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
        Schema::create('movement_lot_allocations', function (Blueprint $table) {
            $table->foreignId('movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->integer('quantity');

            $table->primary(['movement_id', 'lot_id']);
            $table->index('lot_id');
        });

        DB::statement('ALTER TABLE movement_lot_allocations ADD CONSTRAINT movement_lot_allocations_quantity_check
            CHECK (quantity <> 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movement_lot_allocations');
    }
};
