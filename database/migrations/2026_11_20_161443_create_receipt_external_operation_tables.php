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
        foreach (array_unique([config('receipts.legacy_connection'), config('receipts.platform_connection')]) as $connection) {
            $schema = Schema::connection($connection);
            if (! $schema->hasTable('public.libreria_recibo_operaciones')) {
                $schema->create('public.libreria_recibo_operaciones', function (Blueprint $table) {
                    $table->uuid('id')->primary();
                    $table->string('payload_hash', 64);
                    $table->json('result');
                    $table->timestampTz('created_at');
                });
            }
            if ($connection === config('receipts.legacy_connection') && ! $schema->hasTable('public.libreria_recibo_documentos')) {
                $schema->create('public.libreria_recibo_documentos', function (Blueprint $table) {
                    $table->unsignedBigInteger('legacy_receipt_id')->primary();
                    $table->uuid('receipt_uuid')->index();
                    $table->unsignedBigInteger('line_id');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Integration journals are intentionally retained: deleting them permits duplicate external effects.
    }
};
