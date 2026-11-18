<?php

use Database\Seeders\ServiciosAcademicosSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.plataforma' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('sqlite');
    DB::purge('plataforma');
});

function prepareAcademicAssignments(): Connection
{
    $db = DB::connection();
    $db->statement("ATTACH DATABASE ':memory:' AS public");
    $db->statement("ATTACH DATABASE ':memory:' AS libreria");
    $db->getSchemaBuilder()->create('public.productos', function (Blueprint $table) {
        $table->increments('id_producto');
        foreach (['codigo', 'grupo', 'tipo_prod', 'tipo_prec', 'iva2'] as $column) {
            $table->string($column);
        }
    });
    $db->getSchemaBuilder()->create('libreria.servicios_academicos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('producto_id');
        $table->unsignedInteger('grado_id');
        $table->string('especialidad', 128)->default('');
        $table->string('financiamiento', 20)->default('normal');
        $table->string('concepto', 32)->default('colegiatura');
        $table->timestamps();
        $table->unique(['grado_id', 'especialidad', 'financiamiento', 'concepto'], 'servicios_academicos_asignacion_unique');
    });
    foreach (range(1, 28) as $number) {
        $db->table('public.productos')->insert(['codigo' => 'COL-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S', 'tipo_prec' => 'F', 'iva2' => 'E']);
    }
    $platform = DB::connection('plataforma');
    $platform->getSchemaBuilder()->create('school_gradelevels', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->string('title');
    });
    foreach ([2 => '1°', 3 => '2°', 4 => '3°', 5 => '4°', 6 => '5°', 7 => '6°', 59 => '7°', 60 => '8°', 61 => '9°',
        49 => 'Parvularia 4', 50 => 'Parvularia 5', 51 => 'Parvularia 6', 22 => '1er. Año', 23 => '2° Año', 24 => '3er. Año',
        71 => 'Parvularia 4'] as $id => $title) {
        $platform->table('school_gradelevels')->insert(['id' => $id, 'title' => $title]);
    }

    return $db;
}

test('academic assignments persist real grade identifiers specialty and product foreign keys for normal tuition', function () {
    prepareAcademicAssignments();

    $this->seed(ServiciosAcademicosSeeder::class);

    $this->assertDatabaseCount('libreria.servicios_academicos', 32);
    foreach ([49 => 1, 71 => 1, 50 => 2, 51 => 3, 2 => 4, 61 => 12] as $grade => $product) {
        $this->assertDatabaseHas('libreria.servicios_academicos', ['grado_id' => $grade, 'especialidad' => '', 'producto_id' => $product,
            'concepto' => 'colegiatura', 'financiamiento' => 'normal']);
    }
    $this->assertDatabaseHas('libreria.servicios_academicos', ['grado_id' => 22, 'especialidad' => 'bachillerato general', 'producto_id' => 13]);
    $this->assertDatabaseHas('libreria.servicios_academicos', ['grado_id' => 22, 'especialidad' => 'electronica', 'producto_id' => 15]);
    $this->assertDatabaseHas('libreria.servicios_academicos', ['grado_id' => 23, 'especialidad' => 'diseno grafico', 'producto_id' => 25]);
    foreach ([22 => 18, 23 => 19, 24 => 20] as $grade => $product) {
        $this->assertDatabaseHas('libreria.servicios_academicos', ['grado_id' => $grade, 'especialidad' => 'informatica', 'producto_id' => $product]);
        $this->assertDatabaseHas('libreria.servicios_academicos', ['grado_id' => $grade, 'especialidad' => 'desarrollo de software', 'producto_id' => $product]);
    }
    $this->assertDatabaseMissing('libreria.servicios_academicos', ['financiamiento' => 'beca']);
    $this->assertDatabaseMissing('libreria.servicios_academicos', ['concepto' => 'matricula']);
});

test('repeating academic assignment seeding preserves the number and identifiers of existing links', function () {
    $db = prepareAcademicAssignments();
    $this->seed(ServiciosAcademicosSeeder::class);
    $ids = $db->table('libreria.servicios_academicos')->orderBy('id')->pluck('id')->all();

    $this->seed(ServiciosAcademicosSeeder::class);

    expect($db->table('libreria.servicios_academicos')->orderBy('id')->pluck('id')->all())->toBe($ids);
    $this->assertDatabaseCount('libreria.servicios_academicos', 32);
});

test('missing or invalid products prevent all academic assignment writes', function (string $problem) {
    $db = prepareAcademicAssignments();
    if ($problem === 'missing') {
        $db->table('public.productos')->where('codigo', 'COL-028')->delete();
    } else {
        $db->table('public.productos')->where('codigo', 'COL-028')->update(['tipo_prec' => 'V']);
    }

    expect(fn () => $this->seed(ServiciosAcademicosSeeder::class))->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('libreria.servicios_academicos', 0);
})->with(['missing', 'variable price']);

test('missing platform grades prevent all academic assignment writes', function () {
    prepareAcademicAssignments();
    DB::connection('plataforma')->table('school_gradelevels')->where('id', 51)->delete();

    expect(fn () => $this->seed(ServiciosAcademicosSeeder::class))->toThrow(RuntimeException::class, 'No se encontró el grado Parvularia 6');

    $this->assertDatabaseCount('libreria.servicios_academicos', 0);
});
