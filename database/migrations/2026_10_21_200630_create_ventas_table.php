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
        Schema::create('libreria.ventas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('libreria.clientes');
            $table->date('fecha');
            $table->string('caja');
            $table->string('tipo_documento');
            $table->uuid('uuidv4');
            $table->string('numero_control');
            $table->string('operacion');
            $table->string('pagos')->nullable()->default(null);
            $table->string('plazos')->nullable()->default(null);
            $table->decimal('total', 16, 8);
            $table->string('estado', 20)->default('FACTURADO');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('libreria.ventas');
    }
};
