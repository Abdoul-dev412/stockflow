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
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('seq')->nullable();
            $table->string('type', 20)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('payload');
            $table->dateTime('occurred_at')->nullable();
            $table->string('status', 30);
            $table->string('reason', 500)->nullable();
            $table->json('notes')->nullable();
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->dateTime('received_at');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->index(['status', 'received_at']);
            $table->index(['device_id', 'seq']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
