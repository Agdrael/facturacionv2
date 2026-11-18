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

function prepareSaleProducts(TestCase $test): Connection
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $test->actingAs(User::factory()->make(['id' => 1]));
    grantTestRoles(auth()->user());
    $db = DB::connection();
    $db->statement("ATTACH DATABASE ':memory:' AS public");
    $db->statement("ATTACH DATABASE ':memory:' AS inv");
    $db->getSchemaBuilder()->create('inv.inv_bodegas', function (Blueprint $table) {
        $table->integer('bodega_id')->primary();
        $table->string('codigo');
        $table->boolean('activo');
    });
    $db->getSchemaBuilder()->create('inv.inv_existencias', function (Blueprint $table) {
        $table->integer('producto_id');
        $table->integer('bodega_id');
        $table->string('propiedad');
        $table->decimal('stock_actual', 18, 4);
        $table->primary(['producto_id', 'bodega_id', 'propiedad']);
    });
    $db->table('inv.inv_bodegas')->insert(['bodega_id' => 10, 'codigo' => 'PRINCIPAL', 'activo' => true]);
    $db->getSchemaBuilder()->create('public.productos', function (Blueprint $table) {
        $table->increments('id_producto');
        foreach (['codigo', 'descrip', 'grupo', 'tipo_prod', 'tipo_prec', 'unidad', 'iva2', 'c_inv', 'c_gas', 'c_ing'] as $column) {
            $table->string($column)->nullable();
        }
        $table->decimal('p_venta', 26, 6)->nullable();
        $table->boolean('descuento')->nullable();
        $table->decimal('p_costo', 26, 6)->nullable();
    });
    $db->getSchemaBuilder()->create('public.catalogo_contabilidad', function (Blueprint $table) {
        $table->increments('id_catalogo');
        $table->string('cuenta');
    });
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '5101']);

    return $db;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function saleProductRow(array $overrides = []): array
{
    return array_replace([
        'codigo' => 'P-001', 'descrip' => 'Ábaco de prueba', 'grupo' => 'LIBRERIA', 'tipo_prod' => 'B',
        'tipo_prec' => 'F', 'unidad' => '59', 'iva2' => 'A', 'p_venta' => '3.672566',
        'descuento' => true, 'c_ing' => '5101', 'c_inv' => '1101', 'c_gas' => '6101', 'p_costo' => '1.25',
    ], $overrides);
}

test('unauthenticated users cannot query sale products', function () {
    $this->getJson(route('libreria.productos.index'))->assertUnauthorized();
});

test('the real catalog returns goods and services with net price tax accounts and discount configuration', function () {
    $db = prepareSaleProducts($this);
    $db->table('public.productos')->insert([
        saleProductRow(),
        saleProductRow(['codigo' => 'S-001', 'descrip' => 'Servicio exento', 'tipo_prod' => 'S', 'iva2' => 'E', 'tipo_prec' => 'V', 'p_venta' => '10.25', 'descuento' => false, 'grupo' => null]),
    ]);
    $db->table('inv.inv_existencias')->insert(['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'PROPIO', 'stock_actual' => 5]);

    $response = $this->getJson(route('libreria.productos.index'));

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    $products = collect($response->json('data'))->keyBy('code');
    expect($products['P-001'])->toMatchArray([
        'id' => 'product-1', 'productId' => 1, 'name' => 'Ábaco de prueba', 'taxable' => true,
        'origin' => 'LIBRERIA', 'incomeAccount' => '5101', 'inventoryAccount' => '1101', 'expenseAccount' => '6101',
        'unit' => '59', 'priceType' => 'F', 'discountAllowed' => true, 'canSelect' => true, 'configurationError' => null,
    ])->not->toHaveKey('p_costo');
    expect((float) $products['P-001']['netPrice'])->toBe(3.672566);
    expect(round($products['P-001']['price']))->toBe(415.0);
    expect($products['S-001'])->toMatchArray(['productType' => 'S', 'price' => 1025, 'taxable' => false, 'priceType' => 'V', 'discountAllowed' => false, 'canSelect' => true]);
    $this->assertDatabaseCount('public.productos', 2);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'P-001', 'p_venta' => '3.672566']);
});

test('catalog searches match codes and descriptions regardless of case and accents', function (string $search) {
    $db = prepareSaleProducts($this);
    $db->table('public.productos')->insert([
        saleProductRow(),
        saleProductRow(['codigo' => 'P-002', 'descrip' => 'Cuaderno']),
    ]);

    $this->getJson(route('libreria.productos.index', ['q' => $search]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'P-001');
})->with(['abaco', 'ÁBACO', 'p-001', "A\u{0301}baco"]);

test('exact code lookup does not select products whose descriptions merely contain that code', function () {
    $db = prepareSaleProducts($this);
    $db->table('public.productos')->insert([
        saleProductRow(),
        saleProductRow(['codigo' => 'OTHER', 'descrip' => 'Accesorio para P-001']),
    ]);

    $this->getJson(route('libreria.productos.index', ['code' => 'p-001']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.productId', 1);
});

test('academic products stay in the student and fee flow even when looked up by exact code', function () {
    $db = prepareSaleProducts($this);
    $db->table('public.productos')->insert(saleProductRow(['codigo' => 'COL001', 'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S', 'iva2' => 'E', 'p_venta' => 0]));

    $this->getJson(route('libreria.productos.index', ['code' => 'COL001']))
        ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
});

test('product catalogs are paginated without losing entries sharing a description', function () {
    $db = prepareSaleProducts($this);
    foreach (range(1, 21) as $number) {
        $db->table('public.productos')->insert(saleProductRow(['codigo' => 'P-'.$number, 'descrip' => 'Mismo nombre']));
    }

    $this->getJson(route('libreria.productos.index'))->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
    $this->getJson(route('libreria.productos.index', ['page' => 2]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 21)->assertJsonPath('data.0.productId', 21);
});

test('incomplete legacy products remain visible with a reason and cannot be selected', function (array $override, string $message) {
    $db = prepareSaleProducts($this);
    $db->table('public.productos')->insert(saleProductRow($override));

    $this->getJson(route('libreria.productos.index'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.canSelect', false)->assertJsonPath('data.0.configurationError', $message);
})->with([
    'missing description' => [['descrip' => ''], 'Completa el código y la descripción en el catálogo.'],
    'unused product type' => [['tipo_prod' => null], 'Configura el tipo de producto: bien o servicio.'],
    'unused tax classification N' => [['iva2' => 'N'], 'Configura la clasificación de IVA: afecta o exenta.'],
    'missing price type' => [['tipo_prec' => null], 'Configura el tipo de precio: fijo o variable.'],
    'missing price' => [['p_venta' => null], 'Configura un precio de venta válido.'],
    'negative price' => [['p_venta' => -1], 'Configura un precio de venta válido.'],
    'missing income account' => [['c_ing' => null], 'Configura una cuenta de ingreso válida.'],
    'nonexistent income account' => [['c_ing' => '9999'], 'Configura una cuenta de ingreso válida.'],
]);

test('invalid catalog query parameters receive validation errors', function (array $parameters, string $field) {
    prepareSaleProducts($this);

    $this->getJson(route('libreria.productos.index', $parameters))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'oversized search' => [['q' => str_repeat('x', 101)], 'q'],
    'non string search' => [['q' => ['unexpected']], 'q'],
    'oversized code' => [['code' => str_repeat('x', 65)], 'code'],
    'invalid page' => [['page' => 0], 'page'],
]);

test('the catalog reports only principal warehouse stock separated by ownership and leaves services unrestricted', function () {
    $db = prepareSaleProducts($this);
    $db->table('public.productos')->insert([
        saleProductRow(),
        saleProductRow(['codigo' => 'S1', 'tipo_prod' => 'S']),
        saleProductRow(['codigo' => 'EMPTY']),
    ]);
    $db->table('inv.inv_existencias')->insert([
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'CONSIGNADO', 'stock_actual' => '1.25'],
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'PROPIO', 'stock_actual' => '2.5'],
        ['producto_id' => 1, 'bodega_id' => 20, 'propiedad' => 'PROPIO', 'stock_actual' => 100],
    ]);

    $response = $this->getJson(route('libreria.productos.index'));

    $response->assertOk();
    $products = collect($response->json('data'))->keyBy('code');
    expect($products['P-001']['stock'])->toBe(['warehouseId' => 10, 'consigned' => '1.2500', 'own' => '2.5000', 'total' => '3.7500']);
    expect($products['P-001']['canSelect'])->toBeTrue();
    expect($products['S1']['stock'])->toBeNull();
    expect($products['S1']['canSelect'])->toBeTrue();
    expect($products['EMPTY']['canSelect'])->toBeFalse();
});

test('stock checks map fiscal documents and reject cumulative quantities without reserving stock', function (string $invoiceType, string $inventoryType) {
    $db = prepareSaleProducts($this);
    $db->table('public.productos')->insert(saleProductRow());
    $db->table('inv.inv_existencias')->insert([
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'CONSIGNADO', 'stock_actual' => 3],
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'PROPIO', 'stock_actual' => 5],
    ]);

    $this->postJson(route('libreria.venta.disponibilidad'), ['documentType' => $invoiceType, 'items' => [['productId' => 1, 'quantity' => 8]]])
        ->assertOk()->assertJsonPath('allocation.0.property', 'CONSIGNADO')->assertJsonPath('allocation.0.quantity', '3.0000')
        ->assertJsonPath('invoiceDocumentType', $invoiceType)->assertJsonPath('inventoryDocumentType', $inventoryType)
        ->assertJsonPath('allocation.1.property', 'PROPIO')->assertJsonPath('allocation.1.quantity', '5.0000');
    $this->postJson(route('libreria.venta.disponibilidad'), ['documentType' => $invoiceType, 'items' => [['productId' => 1, 'quantity' => 5], ['productId' => 1, 'quantity' => 4]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items');
    $this->assertDatabaseHas('inv.inv_existencias', ['producto_id' => 1, 'propiedad' => 'CONSIGNADO', 'stock_actual' => 3]);
    $this->assertDatabaseHas('inv.inv_existencias', ['producto_id' => 1, 'propiedad' => 'PROPIO', 'stock_actual' => 5]);
})->with(['factura' => ['01', 'DTE-01'], 'credito fiscal' => ['03', 'DTE-03']]);

test('unauthenticated users cannot check stock', function () {
    $this->postJson(route('libreria.venta.disponibilidad'), ['items' => [['productId' => 1, 'quantity' => 1]]])->assertUnauthorized();
});

test('invalid sale quantities cannot reach the stock operation', function (array $items, string $field) {
    prepareSaleProducts($this);

    $this->postJson(route('libreria.venta.disponibilidad'), ['documentType' => '01', 'items' => $items])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'empty sale' => [[], 'items'],
    'zero quantity' => [[['productId' => 1, 'quantity' => 0]], 'items.0.quantity'],
    'negative quantity' => [[['productId' => 1, 'quantity' => -1]], 'items.0.quantity'],
    'fraction beyond sale precision' => [[['productId' => 1, 'quantity' => '0.001']], 'items.0.quantity'],
    'missing product' => [[['quantity' => 1]], 'items.0.productId'],
]);

test('unsupported invoice types cannot be used for stock checks', function () {
    prepareSaleProducts($this);

    $this->postJson(route('libreria.venta.disponibilidad'), ['documentType' => '14', 'items' => [['productId' => 1, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('documentType')
        ->assertJsonPath('errors.documentType.0', 'Selecciona Factura (01) o Comprobante de crédito fiscal (03).');
});

test('stock checks require the selected invoice document type', function () {
    prepareSaleProducts($this);

    $this->postJson(route('libreria.venta.disponibilidad'), ['items' => [['productId' => 1, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('documentType');
});
