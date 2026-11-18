<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libreria.cajas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 4)->unique();
            $table->timestampsTz();
        });
        Schema::create('libreria.caja_sesiones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('caja_id')->constrained(DB::getDriverName() === 'sqlite' ? 'cajas' : 'libreria.cajas')->restrictOnDelete();
            $table->date('fecha_operativa');
            $table->foreignId('abierto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('abierto_nombre');
            $table->string('abierto_empleado', 130);
            $table->timestampTz('abierto_at');
            $table->foreignId('cerrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cerrado_nombre')->nullable();
            $table->string('cerrado_empleado', 130)->nullable();
            $table->timestampTz('cerrado_at')->nullable();
            $table->text('codigo_reapertura')->nullable();
            $table->timestampTz('codigo_usado_at')->nullable();
            $table->foreignId('sesion_anterior_id')->nullable()->constrained(DB::getDriverName() === 'sqlite' ? 'caja_sesiones' : 'libreria.caja_sesiones')->restrictOnDelete();
            $table->text('motivo_reapertura')->nullable();
            $table->timestampsTz();
            $table->index(['caja_id', 'fecha_operativa']);
        });
        $index = DB::getDriverName() === 'sqlite'
            ? 'CREATE UNIQUE INDEX libreria.caja_sesion_abierta_unique ON caja_sesiones (caja_id) WHERE cerrado_at IS NULL'
            : 'CREATE UNIQUE INDEX caja_sesion_abierta_unique ON libreria.caja_sesiones (caja_id) WHERE cerrado_at IS NULL';
        DB::statement($index);
        Schema::create('libreria.arqueos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sesion_id')->unique()->constrained(DB::getDriverName() === 'sqlite' ? 'caja_sesiones' : 'libreria.caja_sesiones')->restrictOnDelete();
            $table->json('snapshot');
            $table->string('estado', 30)->default('pendiente_excel');
            $table->text('original_path')->nullable();
            $table->text('entregado_path')->nullable();
            $table->text('firmado_path')->nullable();
            $table->json('archivos')->nullable();
            $table->timestampTz('entregado_at')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revisado_at')->nullable();
            $table->foreignId('firmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('firmado_nombre')->nullable();
            $table->string('firmado_empleado', 130)->nullable();
            $table->timestampTz('firmado_at')->nullable();
            $table->timestampsTz();
        });
        Schema::table('libreria.ventas', function (Blueprint $table): void {
            $table->foreignId('caja_sesion_id')->nullable()->constrained(DB::getDriverName() === 'sqlite' ? 'caja_sesiones' : 'libreria.caja_sesiones')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('libreria.ventas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('caja_sesion_id');
        });
        Schema::dropIfExists('libreria.arqueos');
        Schema::dropIfExists('libreria.caja_sesiones');
        Schema::dropIfExists('libreria.cajas');
    }
};
