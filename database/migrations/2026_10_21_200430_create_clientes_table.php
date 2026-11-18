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
        Schema::create('libreria.clientes', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('direccion');
            $table->string('telefono_1');
            $table->string('telefono_2')->nullable();
            $table->string('email')->unique();
            $table->string('registro_fiscal')->nullable();
            $table->string('nit')->nullable();
            $table->string('dui')->nullable();
            $table->string('nacionalidad');
            $table->string('departamento');
            $table->string('municipio');
            $table->string('distrito');
            $table->boolean('domiciliado');
            $table->boolean('credito');
            $table->string('tamano')->nullable();
            $table->string('tipo_cliente');
            $table->string('actividad_economica')->nullable();
            $table->string('cuenta_contable');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libreria.clientes');
    }
};
