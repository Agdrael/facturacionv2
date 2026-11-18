<?php

use Database\Seeders\ProductosColegiaturaSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]]);
    DB::purge('sqlite');
});

/** @return array<string, string> */
function expectedTuitionSeedAccounts(): array
{
    return [
        'COL-001' => '51010101030102', 'COL-002' => '51010101010102', 'COL-003' => '51010101020102',
        'COL-004' => '51010102010102', 'COL-005' => '51010102020102', 'COL-006' => '51010102030102',
        'COL-007' => '51010103010102', 'COL-008' => '51010103020102', 'COL-009' => '51010103030102',
        'COL-010' => '51010104010102', 'COL-011' => '51010104020102', 'COL-012' => '51010104030102',
        'COL-013' => '51010110010102', 'COL-014' => '51010110020102',
        'COL-015' => '51010111010102', 'COL-016' => '51010111020102', 'COL-017' => '51010111030102',
        'COL-018' => '51010117010102', 'COL-019' => '51010117020102', 'COL-020' => '51010117030102',
        'COL-021' => '51010118010102', 'COL-022' => '51010118020102', 'COL-023' => '51010118030102',
        'COL-024' => '51010119010102', 'COL-025' => '51010119020102', 'COL-026' => '51010119030102',
        'COL-027' => '51010126010102', 'COL-028' => '51010126020102',
    ];
}

function prepareTuitionSeedCatalogs(): Connection
{
    $db = DB::connection();
    $db->statement("ATTACH DATABASE ':memory:' AS public");
    $schema = $db->getSchemaBuilder();
    $schema->create('public.catalogo_contabilidad', function (Blueprint $table) {
        $table->increments('id_catalogo');
        $table->string('cuenta');
    });
    $schema->create('public.productos', function (Blueprint $table) {
        $table->increments('id_producto');
        foreach (['codigo', 'descrip', 'abreviado', 'grupo', 'tipo_prod', 'tipo_prec', 'iva2', 'c_ing', 'c_inv', 'c_gas'] as $column) {
            $table->string($column)->nullable();
        }
        $table->decimal('p_venta', 11, 6)->nullable();
        $table->integer('ex_actual')->nullable();
        $table->timestamp('fecha_modificacion')->nullable();
    });
    foreach (expectedTuitionSeedAccounts() as $account) {
        $db->table('public.catalogo_contabilidad')->insert(['cuenta' => $account]);
    }

    return $db;
}

test('the seeder creates the 28 exempt fixed price tuition services with the supplied income accounts', function () {
    prepareTuitionSeedCatalogs();

    $this->seed(ProductosColegiaturaSeeder::class);

    $this->assertDatabaseCount('public.productos', 28);
    foreach (expectedTuitionSeedAccounts() as $code => $account) {
        $this->assertDatabaseHas('public.productos', [
            'codigo' => $code, 'c_ing' => $account, 'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S',
            'tipo_prec' => 'F', 'iva2' => 'E', 'p_venta' => null, 'c_inv' => null, 'c_gas' => null,
        ]);
    }
    $this->assertDatabaseHas('public.productos', ['codigo' => 'COL-001', 'descrip' => 'Colegiatura Parvularia 4']);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'COL-002', 'descrip' => 'Colegiatura Parvularia 5']);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'COL-003', 'descrip' => 'Colegiatura Parvularia 6']);
});

test('repeating the seeder keeps product identifiers prices inventory and unrelated products intact', function () {
    $db = prepareTuitionSeedCatalogs();
    $db->table('public.productos')->insert(['codigo' => 'OTRO', 'descrip' => 'Otro producto', 'tipo_prod' => 'B']);
    $this->seed(ProductosColegiaturaSeeder::class);
    $ids = $db->table('public.productos')->pluck('id_producto', 'codigo')->all();
    $db->table('public.productos')->where('codigo', 'COL-001')->update(['p_venta' => '42.123456', 'ex_actual' => 14, 'c_inv' => '1101']);

    $this->seed(ProductosColegiaturaSeeder::class);

    $this->assertDatabaseCount('public.productos', 29);
    expect($db->table('public.productos')->pluck('id_producto', 'codigo')->all())->toBe($ids);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'COL-001', 'p_venta' => '42.123456', 'ex_actual' => 14, 'c_inv' => '1101']);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'OTRO', 'descrip' => 'Otro producto', 'tipo_prod' => 'B']);
});

test('missing income accounts stop the whole seeder before any product is inserted', function () {
    $db = prepareTuitionSeedCatalogs();
    $db->table('public.catalogo_contabilidad')->where('cuenta', '51010126020102')->delete();

    expect(fn () => $this->seed(ProductosColegiaturaSeeder::class))->toThrow(RuntimeException::class, 'Faltan cuentas de ingreso: 51010126020102.');

    $this->assertDatabaseCount('public.productos', 0);
});

test('a generic code belonging to another product stops the whole seeder without overwriting it', function () {
    $db = prepareTuitionSeedCatalogs();
    $db->table('public.productos')->insert(['codigo' => 'COL-013', 'descrip' => 'Producto existente', 'grupo' => 'LIBRERIA', 'tipo_prod' => 'B']);

    expect(fn () => $this->seed(ProductosColegiaturaSeeder::class))->toThrow(RuntimeException::class, 'El código COL-013 pertenece a otro producto.');

    $this->assertDatabaseCount('public.productos', 1);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'COL-013', 'descrip' => 'Producto existente', 'tipo_prod' => 'B']);
});

test('duplicate catalog codes stop the seeder without updating either existing product', function () {
    $db = prepareTuitionSeedCatalogs();
    $db->table('public.productos')->insert([
        ['codigo' => 'COL-001', 'descrip' => 'Primero', 'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S'],
        ['codigo' => 'COL-001', 'descrip' => 'Segundo', 'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S'],
    ]);

    expect(fn () => $this->seed(ProductosColegiaturaSeeder::class))->toThrow(RuntimeException::class, 'El código COL-001 está duplicado.');

    $this->assertDatabaseCount('public.productos', 2);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'COL-001', 'descrip' => 'Primero']);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'COL-001', 'descrip' => 'Segundo']);
});
