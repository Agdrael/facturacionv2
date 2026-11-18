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
        Schema::create('libreria.venta_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venta_id')
                ->constrained('libreria.ventas');

            $table->foreignId('producto_id')
                ->constrained('public.productos', 'id_producto');

            $table->string('carnet_estudiante')->nullable();
            $table->string('nombre');
            $table->decimal('cantidad', 8, 2);
            $table->decimal('valor_unitario', 16, 8);
            $table->decimal('valor_unitario_iva', 16, 8);
            $table->decimal('porcentaje_descuento', 8, 2)->nullable();
            $table->decimal('valor_descuento', 16, 8)->nullable();
            $table->decimal('valor_final', 16, 8);
            $table->string('cuenta_cargo');
            $table->string('cuenta_abono');
            $table->string('origen')->default('LIBRERIA');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libreria.venta_detalles');
    }
};
