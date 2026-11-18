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
        Schema::create('libreria.venta_dtes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->unique()->constrained('libreria.ventas');
            $table->foreignId('user_id')->constrained('public.users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('request_hash', 64);
            $table->uuid('codigo_generacion')->unique();
            $table->string('numero_control', 31)->unique();
            $table->json('documento');
            $table->json('cliente');
            $table->json('pagos');
            $table->foreignId('inventario_doc_id')->nullable()->constrained('inv.inv_documentos', 'doc_id');
            $table->timestamp('guardado_externo_at')->nullable();
            $table->timestamps();
        });
        Schema::create('libreria.venta_cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')->constrained('libreria.ventas');
            $table->string('saldo_id');
            $table->string('financiamiento', 15);
            $table->string('carnet_estudiante');
            $table->integer('anio');
            $table->integer('mes');
            $table->decimal('monto', 16, 8);
            $table->unique(['saldo_id', 'financiamiento', 'anio'], 'venta_cuotas_saldo_financiamiento_anio_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libreria.venta_cuotas');
        Schema::dropIfExists('libreria.venta_dtes');
    }
};
