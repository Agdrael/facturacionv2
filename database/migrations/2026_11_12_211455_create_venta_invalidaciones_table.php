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
        $postgres = DB::connection()->getDriverName() === 'pgsql';
        Schema::create('libreria.venta_invalidaciones', function (Blueprint $table) use ($postgres) {
            $table->id();
            $table->foreignId('venta_dte_id')->unique()->constrained($postgres ? 'libreria.venta_dtes' : 'venta_dtes');
            $table->foreignId('user_id')->constrained($postgres ? 'public.users' : 'users');
            $table->uuid('codigo_generacion')->unique();
            $table->string('request_hash', 64);
            $table->json('documento');
            $table->string('estado', 25)->default('PENDIENTE');
            $table->text('respuesta_api')->nullable();
            $table->text('error')->nullable();
            $table->text('sello_recepcion')->nullable();
            $table->timestamp('guardado_externo_at')->nullable();
            $table->timestamp('intento_at')->nullable();
            $table->timestamp('confirmada_at')->nullable();
            $table->timestamp('aplicada_at')->nullable();
            $table->foreignId('inventario_doc_id')->nullable()->constrained($postgres ? 'inv.inv_documentos' : 'inv_documentos', 'doc_id');
            $table->timestamps();
        });
        Schema::table('libreria.venta_cuotas', function (Blueprint $table) {
            $table->dropUnique('venta_cuotas_saldo_financiamiento_anio_ambiente_unique');
            $table->boolean('reserva_activa')->nullable()->default(true);
            $table->timestamp('liberada_at')->nullable();
            $table->unique(['saldo_id', 'financiamiento', 'anio', 'ambiente', 'reserva_activa'], 'venta_cuotas_reserva_activa_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('libreria.venta_cuotas')->select('saldo_id', 'financiamiento', 'anio', 'ambiente')->groupBy('saldo_id', 'financiamiento', 'anio', 'ambiente')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('No se puede revertir: hay cuotas liberadas y facturadas nuevamente.');
        }
        Schema::table('libreria.venta_cuotas', function (Blueprint $table) {
            $table->dropUnique('venta_cuotas_reserva_activa_unique');
            $table->dropColumn(['reserva_activa', 'liberada_at']);
            $table->unique(['saldo_id', 'financiamiento', 'anio', 'ambiente'], 'venta_cuotas_saldo_financiamiento_anio_ambiente_unique');
        });
        Schema::dropIfExists('libreria.venta_invalidaciones');
    }
};
