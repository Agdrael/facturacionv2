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
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
    ]);
    DB::purge('sqlite');
    $this->withoutVite();
});

function preparePackageCreation(TestCase $test): Connection
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $test->actingAs(User::factory()->make(['id' => 700, 'name' => 'Operador', 'email' => 'operator@example.test']));
    grantTestRoles(auth()->user());
    $db = DB::connection();
    $db->statement("ATTACH DATABASE ':memory:' AS public");
    $schema = $db->getSchemaBuilder();
    $schema->create('public.users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
    $db->table('public.users')->insert(['id' => 700, 'name' => 'Operador', 'email' => 'operator@example.test']);
    $schema->create('public.paquetes_productos', function (Blueprint $table) {
        $table->increments('id_paquete');
        $table->string('codigo_paquete', 100);
        $table->string('nombre_paquete');
        $table->decimal('p_venta', 26, 6);
        $table->date('fecha_creacion');
        $table->unsignedBigInteger('id_usuario');
        $table->foreign('id_usuario')->references('id')->on('users')->restrictOnDelete();
    });
    $schema->create('public.paquetes_desgloce', function (Blueprint $table) {
        $table->increments('id');
        $table->integer('id_paquete');
        $table->integer('producto_id');
        $table->double('cantidad');
        $table->foreign('id_paquete')->references('id_paquete')->on('paquetes_productos')->cascadeOnDelete();
    });
    $schema->create('public.catalogo_contabilidad', function (Blueprint $table) {
        $table->increments('id_catalogo');
        $table->string('cuenta');
    });
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '5101']);
    $schema->create('public.productos', function (Blueprint $table) {
        $table->increments('id_producto');
        foreach (['codigo', 'descrip', 'grupo', 'tipo_prod', 'tipo_prec', 'unidad', 'iva2', 'c_inv', 'c_gas', 'c_ing'] as $column) {
            $table->string($column)->nullable();
        }
        $table->decimal('p_venta', 26, 6)->nullable();
        $table->boolean('descuento')->nullable();
    });
    $db->table('public.productos')->insert([
        ['codigo' => 'P1', 'descrip' => 'Ábaco', 'grupo' => 'LIBRERIA', 'tipo_prod' => 'B', 'tipo_prec' => 'F', 'unidad' => '59', 'iva2' => 'A', 'p_venta' => '10.25', 'c_ing' => '5101', 'descuento' => false],
        ['codigo' => 'S1', 'descrip' => 'Servicio', 'grupo' => 'LIBRERIA', 'tipo_prod' => 'S', 'tipo_prec' => 'V', 'unidad' => '99', 'iva2' => 'E', 'p_venta' => '2.5', 'c_ing' => '5101', 'descuento' => true],
    ]);

    return $db;
}

/** @return array{code: string, name: string, items: list<array{productId: int, quantity: int|float|string}>} */
function newPackagePayload(): array
{
    return ['code' => 'PK-01', 'name' => 'Paquete de prueba', 'items' => [['productId' => 1, 'quantity' => 2], ['productId' => 2, 'quantity' => '1.5']]];
}

test('unauthenticated users cannot create or browse package definitions', function () {
    $this->getJson(route('contaduria.paquetes.index'))->assertUnauthorized();
    $this->getJson(route('contaduria.paquetes.create'))->assertUnauthorized();
    $this->getJson(route('contaduria.paquetes.productos'))->assertUnauthorized();
    $this->postJson(route('contaduria.paquetes.store'), newPackagePayload())->assertUnauthorized();
});

test('package forms and lists render with the authenticated catalog workflow', function () {
    preparePackageCreation($this);

    $this->get(route('contaduria.paquetes.index'))->assertOk()->assertSee('Crear paquete')->assertSee('Aún no hay paquetes registrados.');
    $this->get(route('contaduria.paquetes.create'))->assertOk()->assertSee('Agregar productos')->assertSee('Total sin IVA')
        ->assertSee('data-modal="package-message"', false)->assertSee('name="csrf-token"', false);
});

test('product selection for package definitions does not require stock or inventory tables', function () {
    preparePackageCreation($this);

    $this->getJson(route('contaduria.paquetes.productos', ['q' => 'abaco']))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.productId', 1)->assertJsonPath('data.0.canSelect', true)->assertJsonPath('data.0.stock', null);
});

test('creating a package stores its composition and recalculates the net total with the Laravel user', function () {
    $db = preparePackageCreation($this);
    $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 30));
    $input = newPackagePayload();
    $input['code'] = ' PK-01 ';
    $input['name'] = ' Paquete de prueba ';
    $input['p_venta'] = 9999;
    $input['id_usuario'] = 1;

    $this->followingRedirects()->post(route('contaduria.paquetes.store'), $input)->assertOk()
        ->assertSee('Paquete guardado')->assertSee('data-modal="package-feedback"', false)->assertSee('PK-01');

    $this->assertDatabaseHas('public.paquetes_productos', ['codigo_paquete' => 'PK-01', 'nombre_paquete' => 'Paquete de prueba', 'p_venta' => '24.25', 'id_usuario' => 700, 'fecha_creacion' => '2026-11-11']);
    $this->assertDatabaseHas('public.paquetes_desgloce', ['id_paquete' => 1, 'producto_id' => 1, 'cantidad' => 2]);
    $this->assertDatabaseHas('public.paquetes_desgloce', ['id_paquete' => 1, 'producto_id' => 2, 'cantidad' => 1.5]);
    expect($db->table('public.paquetes_desgloce')->count())->toBe(2);

    $this->getJson(route('libreria.paquetes.index', ['q' => 'PK-01']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.packageId', 1)->assertJsonPath('data.0.componentsCount', 2);
});

test('package totals use current prices and round to six decimals after summing', function () {
    $db = preparePackageCreation($this);
    $db->table('public.productos')->where('id_producto', 1)->update(['p_venta' => '0.000001']);
    $input = ['code' => 'MICRO', 'name' => 'Importe exacto', 'items' => [['productId' => 1, 'quantity' => '0.5']]];

    $this->post(route('contaduria.paquetes.store'), $input)->assertRedirectToRoute('contaduria.paquetes.index');

    $this->assertDatabaseHas('public.paquetes_productos', ['codigo_paquete' => 'MICRO', 'p_venta' => '0.000001']);
});

test('package code duplicates are rejected regardless of case or surrounding whitespace', function () {
    preparePackageCreation($this);
    $this->post(route('contaduria.paquetes.store'), newPackagePayload())->assertRedirect();
    $input = newPackagePayload();
    $input['code'] = ' pk-01 ';

    $this->postJson(route('contaduria.paquetes.store'), $input)->assertUnprocessable()->assertJsonPath('errors.code.0', 'Ya existe un paquete con ese código.');

    $this->assertDatabaseCount('public.paquetes_productos', 1);
    $this->assertDatabaseCount('public.paquetes_desgloce', 2);
});

test('invalid package input cannot create a partial definition', function (string $field, mixed $value, string $errorField) {
    preparePackageCreation($this);
    $input = newPackagePayload();
    data_set($input, $field, $value);

    $this->postJson(route('contaduria.paquetes.store'), $input)->assertUnprocessable()->assertJsonValidationErrors($errorField);

    $this->assertDatabaseCount('public.paquetes_productos', 0);
    $this->assertDatabaseCount('public.paquetes_desgloce', 0);
})->with([
    'empty code' => ['code', '', 'code'],
    'code too long' => ['code', str_repeat('x', 101), 'code'],
    'empty name' => ['name', '', 'name'],
    'empty composition' => ['items', [], 'items'],
    'invalid product' => ['items.0.productId', 999, 'items.0.productId'],
    'duplicate product' => ['items.1.productId', 1, 'items.1.productId'],
    'zero quantity' => ['items.0.quantity', 0, 'items.0.quantity'],
    'negative quantity' => ['items.0.quantity', -1, 'items.0.quantity'],
    'quantity precision' => ['items.0.quantity', '1.001', 'items.0.quantity'],
]);

test('incomplete or academic components are rejected before inserting a package', function (array $changes) {
    $db = preparePackageCreation($this);
    $db->table('public.productos')->where('id_producto', 1)->update($changes);

    $this->postJson(route('contaduria.paquetes.store'), newPackagePayload())->assertUnprocessable()->assertJsonValidationErrors('items.0.productId');

    $this->assertDatabaseCount('public.paquetes_productos', 0);
    $this->assertDatabaseCount('public.paquetes_desgloce', 0);
})->with([
    'missing price' => [['p_venta' => null]],
    'missing account' => [['c_ing' => null]],
    'academic origin' => [['grupo' => 'COLEGIATURA', 'tipo_prod' => 'S']],
]);

test('a detail insert failure rolls back the package header', function () {
    $db = preparePackageCreation($this);
    $db->statement("CREATE TRIGGER public.fail_package_detail BEFORE INSERT ON paquetes_desgloce BEGIN SELECT RAISE(ABORT, 'Test detail failure'); END");

    $this->postJson(route('contaduria.paquetes.store'), newPackagePayload())->assertInternalServerError();

    $this->assertDatabaseCount('public.paquetes_productos', 0);
    $this->assertDatabaseCount('public.paquetes_desgloce', 0);
});

test('validation redirects retain the selected products and show a popup', function () {
    preparePackageCreation($this);
    $input = newPackagePayload();
    $input['name'] = '';
    $this->from(route('contaduria.paquetes.create'))->followingRedirects()->post(route('contaduria.paquetes.store'), $input)
        ->assertOk()->assertSee('Revisa los datos del paquete')->assertSee('data-modal="package-feedback"', false)
        ->assertSee('El campo nombre es obligatorio.')
        ->assertViewHas('options', fn (array $options): bool => count($options['items']) === 2 && $options['items'][0]['productId'] === 1);
});
