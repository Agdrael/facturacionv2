<?php

use App\Services\Libreria\SaleInventoryService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
    DB::purge('sqlite');
});

function prepareInventoryStock(): Connection
{
    $db = DB::connection();
    $db->statement("ATTACH DATABASE ':memory:' AS public");
    $db->statement("ATTACH DATABASE ':memory:' AS inv");
    $db->getSchemaBuilder()->create('public.productos', function (Blueprint $table) {
        $table->integer('id_producto')->primary();
        $table->string('codigo');
        $table->string('tipo_prod');
    });
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
    $db->table('public.productos')->insert(['id_producto' => 1, 'codigo' => 'P1', 'tipo_prod' => 'B']);
    $db->table('inv.inv_bodegas')->insert(['bodega_id' => 10, 'codigo' => 'PRINCIPAL', 'activo' => true]);
    $db->table('inv.inv_existencias')->insert([
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'CONSIGNADO', 'stock_actual' => 3],
        ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'PROPIO', 'stock_actual' => 5],
    ]);

    return $db;
}

test('fiscal document codes map to the original inventory document codes', function (string $invoiceType, string $inventoryType) {
    expect(app(SaleInventoryService::class)->documentTypeForInvoice($invoiceType))->toBe($inventoryType);
})->with([
    'factura' => ['01', 'DTE-01'],
    'credito fiscal' => ['03', 'DTE-03'],
]);

test('unsupported fiscal document types are rejected instead of being treated as credit invoices', function (string $invoiceType) {
    try {
        app(SaleInventoryService::class)->documentTypeForInvoice($invoiceType);
        $this->fail('An unsupported fiscal document must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['documentType' => ['Selecciona Factura (01) o Comprobante de crédito fiscal (03).']]);
    }
})->with(['14', '', 'DTE-01']);

test('stock allocation uses consigned goods first and then own goods from the principal warehouse', function (int $quantity, array $expected) {
    prepareInventoryStock();

    $allocation = app(SaleInventoryService::class)->withLockedStock([['productId' => 1, 'quantity' => $quantity]], fn (array $allocation): array => $allocation);

    expect($allocation)->toBe($expected);
})->with([
    'consigned only' => [2, [['productId' => 1, 'warehouseId' => 10, 'property' => 'CONSIGNADO', 'quantity' => '2.0000', 'movementType' => 'VENTA_CONSIGNADO_OUT']]],
    'mixed ownership' => [8, [
        ['productId' => 1, 'warehouseId' => 10, 'property' => 'CONSIGNADO', 'quantity' => '3.0000', 'movementType' => 'VENTA_CONSIGNADO_OUT'],
        ['productId' => 1, 'warehouseId' => 10, 'property' => 'PROPIO', 'quantity' => '5.0000', 'movementType' => 'VENTA'],
    ]],
]);

test('own stock is used when there is no consigned balance', function () {
    $db = prepareInventoryStock();
    $db->table('inv.inv_existencias')->where('propiedad', 'CONSIGNADO')->delete();

    $allocation = app(SaleInventoryService::class)->withLockedStock([['productId' => 1, 'quantity' => 4]], fn (array $allocation): array => $allocation);

    expect($allocation)->toBe([['productId' => 1, 'warehouseId' => 10, 'property' => 'PROPIO', 'quantity' => '4.0000', 'movementType' => 'VENTA']]);
});

test('availability ignores other warehouses and reports missing balances as zero', function () {
    $db = prepareInventoryStock();
    $db->table('inv.inv_existencias')->insert(['producto_id' => 1, 'bodega_id' => 20, 'propiedad' => 'PROPIO', 'stock_actual' => 100]);

    $stock = app(SaleInventoryService::class)->availability([1, 2]);

    expect($stock)->toBe([
        1 => ['warehouseId' => 10, 'consigned' => '3.0000', 'own' => '5.0000', 'total' => '8.0000'],
        2 => ['warehouseId' => 10, 'consigned' => '0.0000', 'own' => '0.0000', 'total' => '0.0000'],
    ]);
});

test('duplicate product lines share a single stock balance and cannot bypass the quantity limit', function () {
    prepareInventoryStock();
    $called = false;

    try {
        app(SaleInventoryService::class)->withLockedStock([['productId' => 1, 'quantity' => 5], ['productId' => 1, 'quantity' => 4]], function () use (&$called): void {
            $called = true;
        });
        $this->fail('The cumulative quantity must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['items'][0])->toBe('Stock insuficiente para P1. Disponible en bodega principal: 8.0000.');
    }

    expect($called)->toBeFalse();
});

test('confirmation rereads stock changed since the catalog was opened', function () {
    $db = prepareInventoryStock();
    $inventory = app(SaleInventoryService::class);
    expect($inventory->availability([1])[1]['total'])->toBe('8.0000');
    $db->table('inv.inv_existencias')->where('propiedad', 'CONSIGNADO')->update(['stock_actual' => 0]);
    $db->table('inv.inv_existencias')->where('propiedad', 'PROPIO')->update(['stock_actual' => 1]);

    expect(fn () => $inventory->withLockedStock([['productId' => 1, 'quantity' => 4]], fn (): null => null))->toThrow(ValidationException::class);
});

test('a failed save rolls back writes made while the stock locks are held', function () {
    $db = prepareInventoryStock();

    expect(fn () => app(SaleInventoryService::class)->withLockedStock([['productId' => 1, 'quantity' => 1]], function () use ($db): void {
        $db->table('public.productos')->where('id_producto', 1)->update(['codigo' => 'CHANGED']);
        $db->table('inv.inv_existencias')->where('propiedad', 'CONSIGNADO')->update(['stock_actual' => 2]);
        throw new RuntimeException('Could not save the sale.');
    }))->toThrow(RuntimeException::class, 'Could not save the sale.');

    $this->assertDatabaseHas('public.productos', ['id_producto' => 1, 'codigo' => 'P1']);
    $this->assertDatabaseHas('inv.inv_existencias', ['propiedad' => 'CONSIGNADO', 'stock_actual' => 3]);
    expect($db->transactionLevel())->toBe(0);
});

test('fractional stock is allocated without floating point rounding', function () {
    $db = prepareInventoryStock();
    $db->table('inv.inv_existencias')->where('propiedad', 'CONSIGNADO')->update(['stock_actual' => '0.1']);
    $db->table('inv.inv_existencias')->where('propiedad', 'PROPIO')->update(['stock_actual' => '0.2']);

    $allocation = app(SaleInventoryService::class)->withLockedStock([['productId' => 1, 'quantity' => '0.3']], fn (array $allocation): array => $allocation);

    expect(array_column($allocation, 'quantity'))->toBe(['0.1000', '0.2000']);
});

test('services require no physical stock or principal warehouse', function () {
    $db = prepareInventoryStock();
    $db->table('public.productos')->where('id_producto', 1)->update(['tipo_prod' => 'S']);
    $db->table('inv.inv_bodegas')->delete();

    $allocation = app(SaleInventoryService::class)->withLockedStock([['productId' => 1, 'quantity' => 100]], fn (array $allocation): array => $allocation);

    expect($allocation)->toBe([]);
});

test('missing products and inactive principal warehouses reject stock operations', function (bool $missingProduct) {
    $db = prepareInventoryStock();
    if ($missingProduct) {
        $db->table('public.productos')->delete();
    } else {
        $db->table('inv.inv_bodegas')->update(['activo' => false]);
    }

    expect(fn () => app(SaleInventoryService::class)->withLockedStock([['productId' => 1, 'quantity' => 1]], fn (): null => null))->toThrow(ValidationException::class);
})->with([true, false]);

test('stock operations reject empty sales and unsupported quantity precision', function (array $items) {
    prepareInventoryStock();

    expect(fn () => app(SaleInventoryService::class)->withLockedStock($items, fn (): null => null))->toThrow(ValidationException::class);
})->with([
    'empty sale' => [[]],
    'quantity beyond inventory precision' => [[['productId' => 1, 'quantity' => '0.00001']]],
]);
