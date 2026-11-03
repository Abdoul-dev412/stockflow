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
        Schema::create('anomalies', function (Blueprint $table) {
            $table->id();
            $table->string('rule_code', 4);
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->string('status', 10)->default('new');
            $table->text('comment')->nullable();
            $table->string('message', 500);
            $table->json('details')->nullable();
            $table->string('dedupe_key', 100)->unique();
            $table->dateTime('occurred_at');
            $table->dateTime('detected_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();

            $table->index(['status', 'detected_at']);
            $table->index(['rule_code', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anomalies');
    }
};
