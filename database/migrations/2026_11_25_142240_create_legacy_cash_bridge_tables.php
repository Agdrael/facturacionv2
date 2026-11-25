<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('libreria.legacy_cash_registers')) {
            Schema::create('libreria.legacy_cash_registers', function (Blueprint $table) {
                $table->string('register', 4)->primary();
                $table->boolean('active')->default(false);
                $table->timestampTz('activated_at')->nullable();
            });
        }
        if (! Schema::hasTable('libreria.legacy_cash_sessions')) {
            Schema::create('libreria.legacy_cash_sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('legacy_id')->primary();
                $table->string('register', 4)->index();
                $table->timestampTz('opened_at');
                $table->timestampTz('closed_at')->nullable();
                $table->unsignedBigInteger('reconciliation_id')->nullable()->index();
            });
        }
        if (! Schema::hasTable('libreria.legacy_cash_movements')) {
            Schema::create('libreria.legacy_cash_movements', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 8);
                $table->unsignedBigInteger('legacy_id');
                $table->string('register', 4)->index();
                $table->unsignedBigInteger('legacy_session_id')->nullable()->index();
                $table->timestampTz('recorded_at')->nullable();
                $table->decimal('confirmed_amount', 18, 2)->nullable();
                $table->string('confirmed_method', 12)->nullable();
                $table->boolean('excluded')->default(false);
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestampTz('confirmed_at')->nullable();
                $table->text('reason')->nullable();
                $table->unique(['kind', 'legacy_id']);
            });
        }
        if (! Schema::hasTable('libreria.legacy_cash_audits')) {
            Schema::create('libreria.legacy_cash_audits', function (Blueprint $table) {
                $table->id();
                $table->string('register', 4);
                $table->string('action', 24);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('data');
                $table->timestampTz('created_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retain journals and document associations to prevent duplicate imports.
        DB::table('libreria.legacy_cash_registers')->update(['active' => false]);
    }
};
