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
        Schema::table('libreria.venta_dtes', function (Blueprint $table) {
            $table->string('transmision_estado', 25)->default('PENDIENTE');
            $table->text('respuesta_api')->nullable();
            $table->text('transmision_error')->nullable();
            $table->text('sello_recepcion')->nullable();
            $table->timestamp('transmision_intento_at')->nullable();
            $table->timestamp('transmision_confirmada_at')->nullable();
            $table->timestamp('pagos_aplicados_at')->nullable();
        });
        Schema::table('libreria.venta_cuotas', function (Blueprint $table) {
            $table->dropUnique('venta_cuotas_saldo_financiamiento_anio_unique');
            $table->string('ambiente', 2)->default('01');
        });
        foreach (DB::table('libreria.venta_dtes')->get(['venta_id', 'documento']) as $dte) {
            $document = json_decode($dte->documento, true, 512, JSON_THROW_ON_ERROR);
            DB::table('libreria.venta_cuotas')->where('venta_id', $dte->venta_id)->update(['ambiente' => $document['identificacion']['ambiente']]);
        }
        Schema::table('libreria.venta_cuotas', function (Blueprint $table) {
            $table->unique(['saldo_id', 'financiamiento', 'anio', 'ambiente'], 'venta_cuotas_saldo_financiamiento_anio_ambiente_unique');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            $start = now('UTC')->format('YmdHis').'0';
            DB::statement('CREATE SEQUENCE libreria.contador_dte_pruebas_01_seq START WITH '.$start);
            DB::statement('CREATE SEQUENCE libreria.contador_dte_pruebas_03_seq START WITH '.$start);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('libreria.venta_cuotas')->select('saldo_id', 'financiamiento', 'anio')->groupBy('saldo_id', 'financiamiento', 'anio')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('No se puede revertir mientras una cuota tenga referencias en ambos ambientes.');
        }
        Schema::table('libreria.venta_cuotas', function (Blueprint $table) {
            $table->dropUnique('venta_cuotas_saldo_financiamiento_anio_ambiente_unique');
            $table->dropColumn('ambiente');
            $table->unique(['saldo_id', 'financiamiento', 'anio'], 'venta_cuotas_saldo_financiamiento_anio_unique');
        });
        Schema::table('libreria.venta_dtes', function (Blueprint $table) {
            $table->dropColumn(['transmision_estado', 'respuesta_api', 'transmision_error', 'sello_recepcion', 'transmision_intento_at', 'transmision_confirmada_at', 'pagos_aplicados_at']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE libreria.contador_dte_pruebas_01_seq');
            DB::statement('DROP SEQUENCE libreria.contador_dte_pruebas_03_seq');
        }
    }
};
