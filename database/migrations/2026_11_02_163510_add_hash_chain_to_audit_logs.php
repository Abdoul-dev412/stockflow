<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Services\AuditChain;


return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->char('previous_hash', 64)->nullable()->after('details');
            $table->char('hash', 64)->nullable()->unique()->after('previous_hash');
        });

        Schema::create('audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->char('last_hash', 64)->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // Rétro-remplissage des entrées déjà présentes (base de développement alimentée avant ce jour).
        app(AuditChain::class)->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropUnique(['hash']);
            $table->dropColumn(['previous_hash', 'hash']);
        });
    }
};
