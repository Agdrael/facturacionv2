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
        Schema::create('libreria.servicios_academicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producto_id')->constrained('public.productos', 'id_producto')->restrictOnDelete();
            $table->unsignedInteger('grado_id');
            $table->string('especialidad', 128)->default('');
            $table->string('financiamiento', 20)->default('normal');
            $table->string('concepto', 32)->default('colegiatura');
            $table->timestamps();
            $table->unique(['grado_id', 'especialidad', 'financiamiento', 'concepto'], 'servicios_academicos_asignacion_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libreria.servicios_academicos');
    }
};
