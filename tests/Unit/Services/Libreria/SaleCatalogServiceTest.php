<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\User;
use App\Services\GeographyService;
use App\Services\Libreria\SaleCatalogService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Tests\TestCase;

require_once __DIR__.'/../../../Fixtures/AccessDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
});

/** @return array{Connection, Connection} */
function prepareSaleCatalogs(): array
{
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $catalogs = DB::connection('dtesantacecilia');
    $clients = DB::connection();
    $catalogs->statement("ATTACH DATABASE ':memory:' AS public");
    $clients->statement("ATTACH DATABASE ':memory:' AS libreria");
    (require database_path('migrations/2026_10_21_200430_create_clientes_table.php'))->up();

    foreach ([
        '002' => [['01', 'Factura'], ['03', 'Comprobante de crédito fiscal'], ['14', 'Sujeto excluido']],
        '012' => [['01', 'Departamento uno'], ['02', 'Departamento dos']],
        '016' => [['1', 'Contado'], ['2', 'A crédito'], ['3', 'Otro']],
        '017' => [['01', 'Billetes y monedas'], ['02', 'Tarjeta Débito']],
        '018' => [['01', 'Días'], ['02', 'Meses'], ['03', 'Años']],
        '019' => [['12345', 'Actividad de prueba'], ['67890', 'Otra actividad']],
        '022' => [['13', 'DUI'], ['36', 'NIT'], ['03', 'Pasaporte']],
    ] as $number => $rows) {
        $tableName = 'public.CAT-'.$number;
        $catalogs->getSchemaBuilder()->create($tableName, function (Blueprint $table) {
            $table->string('codigo');
            $table->string('valores');
        });
        foreach ($rows as [$code, $label]) {
            $catalogs->table($tableName)->insert(['codigo' => $code, 'valores' => $label]);
        }
    }
    $catalogs->getSchemaBuilder()->create('public.municipios', function (Blueprint $table) {
        $table->integer('id_muni');
        $table->string('codigo');
        $table->string('nombre');
        $table->string('departamento_codigo');
    });
    $catalogs->getSchemaBuilder()->create('public.distritos', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->string('nombre');
        $table->string('departamento_codigo');
        $table->integer('municipio_codigo');
    });
    $catalogs->table('public.municipios')->insert([
        ['id_muni' => 1, 'codigo' => '01', 'nombre' => 'Municipio uno', 'departamento_codigo' => '01'],
        ['id_muni' => 2, 'codigo' => '01', 'nombre' => 'Municipio dos', 'departamento_codigo' => '02'],
    ]);
    $catalogs->table('public.distritos')->insert([
        ['id' => 7, 'nombre' => 'Distrito uno', 'departamento_codigo' => '01', 'municipio_codigo' => 1],
        ['id' => 8, 'nombre' => 'Distrito dos', 'departamento_codigo' => '02', 'municipio_codigo' => 1],
    ]);

    return [$catalogs, $clients];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function saleCatalogCustomer(array $overrides = []): array
{
    return array_replace([
        'id' => 1, 'nombre' => 'Ana de prueba', 'direccion' => 'Dirección de prueba',
        'telefono_1' => '2222-3333', 'telefono_2' => null, 'email' => 'ana@example.test',
        'registro_fiscal' => '123456', 'dui' => '01234567-8', 'nit' => '0614-123456-789-0',
        'nacionalidad' => 'El Salvador', 'departamento' => '01', 'distrito' => '7', 'municipio' => '01',
        'domiciliado' => true, 'credito' => false, 'tipo_cliente' => 'normal',
        'actividad_economica' => '12345', 'cuenta_contable' => '11010101',
    ], $overrides);
}

function authenticateSaleCatalogUser(TestCase $test): void
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $test->actingAs(User::factory()->make(['id' => 1]));
    grantTestRoles(auth()->user());
}

test('sales load database catalogs while restricting invoice documents to 01 and 03', function () {
    prepareSaleCatalogs();

    $options = app(SaleCatalogService::class)->options();

    expect(array_column($options['documents'], 'codigo'))->toBe(['01', '03']);
    expect($options['identityDocuments'])->toContain(['codigo' => '36', 'valor' => 'NIT']);
    expect($options['paymentMethods'][0])->toBe(['codigo' => '01', 'valor' => 'Billetes y monedas']);
    expect(array_column($options['operations'], 'codigo'))->toBe(['1', '2', '3']);
    expect($options['creditTerms'])->toContain(['codigo' => '03', 'valor' => 'Años']);
    expect($options['economicActivities'])->toContain(['codigo' => '12345', 'valor' => 'Actividad de prueba']);
});

test('the reusable geography service resolves each district municipality within its own department', function () {
    prepareSaleCatalogs();

    $options = app(GeographyService::class)->options();

    expect($options['departments'])->toHaveCount(2);
    expect($options['districts'])->toContain([
        'codigo' => '7', 'valor' => 'Distrito uno', 'department' => '01',
        'municipalityCode' => '01', 'municipalityName' => 'Municipio uno',
    ], [
        'codigo' => '8', 'valor' => 'Distrito dos', 'department' => '02',
        'municipalityCode' => '01', 'municipalityName' => 'Municipio dos',
    ]);
});

test('a district with no municipality in its department produces an explicit data error', function () {
    [$catalogs] = prepareSaleCatalogs();
    $catalogs->table('public.distritos')->insert(['id' => 9, 'nombre' => 'Huérfano', 'departamento_codigo' => '01', 'municipio_codigo' => 99]);

    expect(fn () => app(GeographyService::class)->options())->toThrow(
        UnexpectedValueException::class, 'El distrito 9 no tiene un municipio correspondiente en su departamento.',
    );
});

test('the sales route renders database options and provides the protected client lookup url', function () {
    [, $clients] = prepareSaleCatalogs();
    $clients->table('libreria.clientes')->insert(saleCatalogCustomer(['nombre' => 'CLIENTES VARIOS', 'email' => 'cv@example.test']));
    authenticateSaleCatalogUser($this);
    $this->withoutVite();

    $response = $this->get(route('libreria.venta'));

    $response->assertOk()->assertViewIs('libreria.venta')
        ->assertViewHas('saleCatalogs', fn (array $options): bool => $options['defaultClient']['id'] === 1
            && $options['defaultClient']['name'] === 'CLIENTES VARIOS' && $options['defaultClient']['email'] === 'cv@example.test')
        ->assertViewHas('saleCatalogs', fn (array $options): bool => $options['clientSearchUrl'] === route('libreria.clientes.index'))
        ->assertViewHas('saleCatalogs', fn (array $options): bool => $options['studentSearchUrl'] === route('libreria.alumnos.index'))
        ->assertViewHas('saleCatalogs', fn (array $options): bool => $options['productSearchUrl'] === route('libreria.productos.index'))
        ->assertViewHas('saleCatalogs', fn (array $options): bool => $options['packageSearchUrl'] === route('libreria.paquetes.index'))
        ->assertViewHas('saleCatalogs', fn (array $options): bool => $options['stockCheckUrl'] === route('libreria.venta.disponibilidad'))
        ->assertSee('Billetes y monedas')->assertSee('Comprobante de crédito fiscal')->assertSee('Seleccionar cliente')
        ->assertSee('data-modal="sale-client-picker"', false)->assertSee('Actividad de prueba')
        ->assertSee('name="csrf-token"', false)
        ->assertSee('Agregar y otro alumno')
        ->assertSee('Beca y bienhechor pueden incluir meses anteriores.');
});

test('unauthenticated users cannot query billing clients', function () {
    $this->getJson(route('libreria.clientes.index'))->assertUnauthorized();
});

test('client searches return only matching billing data without internal account fields', function (string $search) {
    [, $clients] = prepareSaleCatalogs();
    $clients->table('libreria.clientes')->insert([
        saleCatalogCustomer(),
        saleCatalogCustomer(['id' => 2, 'nombre' => 'Otra persona', 'email' => 'otra@example.test', 'dui' => '11234567-8', 'nit' => '0714-123456-789-0']),
    ]);
    authenticateSaleCatalogUser($this);

    $response = $this->getJson(route('libreria.clientes.index', ['q' => $search]));

    $response->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.documents.13', '01234567-8')
        ->assertJsonPath('data.0.documents.36', '0614-123456-789-0')
        ->assertJsonPath('data.0.department', '01')->assertJsonPath('data.0.phone2', null)
        ->assertJsonPath('data.0.activity', '12345')
        ->assertJsonMissingPath('data.0.cuenta_contable');
})->with(['case insensitive name' => ['ANA'], 'DUI without separators' => ['012345678'], 'NIT without separators' => ['06141234567890']]);

test('client searches are limited and use deterministic name ordering', function () {
    [, $clients] = prepareSaleCatalogs();
    foreach (range(24, 0) as $index) {
        $clients->table('libreria.clientes')->insert(saleCatalogCustomer([
            'id' => $index + 1, 'nombre' => 'Cliente '.str_pad((string) $index, 2, '0', STR_PAD_LEFT), 'email' => 'cliente'.$index.'@example.test',
        ]));
    }
    authenticateSaleCatalogUser($this);

    $this->getJson(route('libreria.clientes.index'))->assertOk()->assertJsonCount(20, 'data')
        ->assertJsonPath('data.0.name', 'Cliente 00')->assertJsonPath('data.19.name', 'Cliente 19');
});

test('invalid client search input receives a validation response', function (mixed $search) {
    prepareSaleCatalogs();
    authenticateSaleCatalogUser($this);

    $this->getJson(route('libreria.clientes.index', ['q' => $search]))->assertUnprocessable()
        ->assertJsonValidationErrors('q');
})->with(['too long' => [str_repeat('x', 101)], 'array' => [['unexpected']]]);
