<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Tests\TestCase;

require_once __DIR__.'/../../../../Fixtures/AccessDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
    DB::purge('sqlite');
});

function prepareSalePackages(TestCase $test): Connection
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $test->actingAs(User::factory()->make(['id' => 1]));
    grantTestRoles(auth()->user());
    $db = DB::connection();
    $db->statement("ATTACH DATABASE ':memory:' AS public");
    $db->statement("ATTACH DATABASE ':memory:' AS inv");
    $schema = $db->getSchemaBuilder();
    $schema->create('public.paquetes_productos', function (Blueprint $table) {
        $table->increments('id_paquete');
        $table->string('codigo_paquete');
        $table->string('nombre_paquete');
        $table->decimal('p_venta', 18, 6)->nullable();
    });
    $schema->create('public.paquetes_desgloce', function (Blueprint $table) {
        $table->increments('id');
        $table->integer('id_paquete');
        $table->integer('producto_id');
        $table->double('cantidad')->nullable();
    });
    $schema->create('public.productos', function (Blueprint $table) {
        $table->increments('id_producto');
        foreach (['codigo', 'descrip', 'grupo', 'tipo_prod', 'tipo_prec', 'unidad', 'iva2', 'c_inv', 'c_gas', 'c_ing'] as $column) {
            $table->string($column)->nullable();
        }
        $table->decimal('p_venta', 18, 6)->nullable();
        $table->boolean('descuento')->nullable();
    });
    $schema->create('public.catalogo_contabilidad', function (Blueprint $table) {
        $table->increments('id_catalogo');
        $table->string('cuenta');
    });
    $schema->create('inv.inv_bodegas', function (Blueprint $table) {
        $table->integer('bodega_id')->primary();
        $table->string('codigo');
        $table->boolean('activo');
    });
    $schema->create('inv.inv_existencias', function (Blueprint $table) {
        $table->integer('producto_id');
        $table->integer('bodega_id');
        $table->string('propiedad');
        $table->decimal('stock_actual', 18, 4);
    });
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '5101']);
    $db->table('inv.inv_bodegas')->insert(['bodega_id' => 10, 'codigo' => 'PRINCIPAL', 'activo' => true]);

    return $db;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function packageSaleProduct(array $overrides = []): array
{
    return array_replace(['codigo' => 'P1', 'descrip' => 'Libro de prueba', 'grupo' => 'LIBRERIA', 'tipo_prod' => 'B',
        'tipo_prec' => 'F', 'unidad' => '59', 'iva2' => 'E', 'c_ing' => '5101', 'c_inv' => '1101', 'c_gas' => '6101',
        'p_venta' => '10.25', 'descuento' => false], $overrides);
}

test('unauthenticated users cannot list or read package contents', function () {
    $this->getJson(route('libreria.paquetes.index'))->assertUnauthorized();
    $this->getJson(route('libreria.paquetes.show', 1))->assertUnauthorized();
});

test('the package catalog searches names without accents and reports distinct component counts', function (string $search) {
    $db = prepareSalePackages($this);
    $db->table('public.paquetes_productos')->insert([
        ['codigo_paquete' => 'PK-01', 'nombre_paquete' => 'Útiles de primer grado'],
        ['codigo_paquete' => 'PK-02', 'nombre_paquete' => 'Paquete sin componentes'],
    ]);
    $db->table('public.paquetes_desgloce')->insert([
        ['id_paquete' => 1, 'producto_id' => 1, 'cantidad' => 1],
        ['id_paquete' => 1, 'producto_id' => 1, 'cantidad' => 2],
        ['id_paquete' => 1, 'producto_id' => 2, 'cantidad' => 1],
    ]);

    $this->getJson(route('libreria.paquetes.index', ['q' => $search]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.packageId', 1)
        ->assertJsonPath('data.0.componentsCount', 2)->assertJsonPath('data.0.canSelect', true)
        ->assertJsonPath('data.0.detailUrl', '/libreria/paquetes/1');
})->with(['utiles', 'ÚTILES', 'pk-01']);

test('an empty package catalog returns an empty result and a package with no components cannot be selected', function () {
    $db = prepareSalePackages($this);
    $this->getJson(route('libreria.paquetes.index'))->assertOk()->assertJsonCount(0, 'data');
    $db->table('public.paquetes_productos')->insert(['codigo_paquete' => 'EMPTY', 'nombre_paquete' => 'Vacío']);

    $this->getJson(route('libreria.paquetes.index'))->assertOk()->assertJsonPath('data.0.canSelect', false);
    $this->getJson(route('libreria.paquetes.show', 1))->assertUnprocessable()->assertJsonValidationErrors('package');
});

test('package contents use current product prices quantities tax accounts and principal stock', function () {
    $db = prepareSalePackages($this);
    $db->table('public.paquetes_productos')->insert(['codigo_paquete' => 'PK1', 'nombre_paquete' => 'Paquete', 'p_venta' => 999]);
    $db->table('public.productos')->insert([
        packageSaleProduct(),
        packageSaleProduct(['codigo' => 'S1', 'descrip' => 'Servicio', 'tipo_prod' => 'S', 'iva2' => 'A', 'tipo_prec' => 'V', 'p_venta' => '3.672566', 'descuento' => true]),
    ]);
    $db->table('public.paquetes_desgloce')->insert([
        ['id_paquete' => 1, 'producto_id' => 1, 'cantidad' => 2],
        ['id_paquete' => 1, 'producto_id' => 1, 'cantidad' => 1.5],
        ['id_paquete' => 1, 'producto_id' => 2, 'cantidad' => 1],
    ]);
    $db->table('inv.inv_existencias')->insert([
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'CONSIGNADO', 'stock_actual' => 3],
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'PROPIO', 'stock_actual' => 5],
        ['producto_id' => 1, 'bodega_id' => 20, 'propiedad' => 'PROPIO', 'stock_actual' => 100],
    ]);

    $response = $this->getJson(route('libreria.paquetes.show', 1));

    $response->assertOk()->assertJsonCount(2, 'products')->assertJsonPath('products.0.productId', 1)
        ->assertJsonPath('products.0.quantity', 3.5)->assertJsonPath('products.0.price', 1025)
        ->assertJsonPath('products.0.incomeAccount', '5101')->assertJsonPath('products.0.inventoryAccount', '1101')
        ->assertJsonPath('products.0.stock.total', '8.0000')->assertJsonPath('products.0.discountAllowed', false)
        ->assertJsonPath('products.0.priceType', 'F')->assertJsonPath('products.1.productType', 'S')
        ->assertJsonPath('products.1.stock', null)->assertJsonPath('products.1.discountAllowed', true)->assertJsonPath('products.1.priceType', 'V');
    expect(round($response->json('products.1.price')))->toBe(415.0);
    $this->assertDatabaseHas('inv.inv_existencias', ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'CONSIGNADO', 'stock_actual' => 3]);
});

test('missing components are reported instead of silently returning a partial package', function () {
    $db = prepareSalePackages($this);
    $db->table('public.paquetes_productos')->insert(['codigo_paquete' => 'PK1', 'nombre_paquete' => 'Paquete']);
    $db->table('public.paquetes_desgloce')->insert(['id_paquete' => 1, 'producto_id' => 999, 'cantidad' => 1]);

    $this->getJson(route('libreria.paquetes.show', 1))->assertUnprocessable()
        ->assertJsonPath('errors.package.0', 'El paquete contiene productos que ya no existen. Revisa su configuración.');
});

test('invalid package quantities prevent loading its products', function (mixed $quantity) {
    $db = prepareSalePackages($this);
    $db->table('public.paquetes_productos')->insert(['codigo_paquete' => 'PK1', 'nombre_paquete' => 'Paquete']);
    $db->table('public.paquetes_desgloce')->insert(['id_paquete' => 1, 'producto_id' => 1, 'cantidad' => $quantity]);

    $this->getJson(route('libreria.paquetes.show', 1))->assertUnprocessable()->assertJsonValidationErrors('package');
})->with([0, -1, null, 0.001, 1000000]);

test('academic products cannot bypass the student selection flow through a package', function () {
    $db = prepareSalePackages($this);
    $db->table('public.paquetes_productos')->insert(['codigo_paquete' => 'PK1', 'nombre_paquete' => 'Paquete']);
    $db->table('public.productos')->insert(packageSaleProduct(['grupo' => 'COLEGIATURA', 'tipo_prod' => 'S']));
    $db->table('public.paquetes_desgloce')->insert(['id_paquete' => 1, 'producto_id' => 1, 'cantidad' => 1]);

    $this->getJson(route('libreria.paquetes.show', 1))->assertOk()->assertJsonPath('products.0.canSelect', false)
        ->assertJsonPath('products.0.configurationError', 'Agrega las matrículas y colegiaturas desde la selección de alumno y cuotas.');
});

test('package lists paginate and missing package identifiers return 404', function () {
    $db = prepareSalePackages($this);
    foreach (range(1, 21) as $id) {
        $db->table('public.paquetes_productos')->insert(['codigo_paquete' => 'PK'.$id, 'nombre_paquete' => 'Paquete']);
    }

    $this->getJson(route('libreria.paquetes.index', ['page' => 2]))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 21)->assertJsonPath('data.0.packageId', 21);
    $this->getJson(route('libreria.paquetes.show', 999))->assertNotFound();
});
