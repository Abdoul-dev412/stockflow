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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name', 150)->index();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('unit', 20)->default('unité');
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('minimum_stock')->default(0);
            $table->boolean('tracks_lots')->default(false);
            $table->unsignedSmallInteger('target_cover_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category_id']);
    });

    DB::statement('ALTER TABLE products ADD CONSTRAINT products_prices_check
        CHECK (purchase_price >= 0 AND (sale_price IS NULL OR sale_price >= 0))');
        }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
