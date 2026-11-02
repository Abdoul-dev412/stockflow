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
        Schema::create('integrity_checks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 10);
            $table->unsignedInteger('issues_count')->default(0);
            $table->char('signature', 64)->nullable();
            $table->json('details')->nullable();
            $table->dateTime('checked_at');

            $table->index(['type', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrity_checks');
    }
};
