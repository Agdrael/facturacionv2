<?php

use App\Models\Dte\Cat001;
use App\Models\Dte\Cat020;
use App\Models\Dte\Cat027;
use App\Models\Dte\Distrito;
use App\Models\Dte\Municipio;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    ]);
});

function prepareDteCatalogDatabase(): Connection
{
    DB::purge('dtesantacecilia');
    $database = DB::connection('dtesantacecilia');
    $database->statement("ATTACH DATABASE ':memory:' AS public");

    return $database;
}

function createDteCatalogTable(Connection $database, string $table): void
{
    $database->getSchemaBuilder()->create($table, function (Blueprint $table) {
        $table->string('codigo');
        $table->string('valores');
    });
}

function createMunicipalityCatalogTable(Connection $database): void
{
    $database->getSchemaBuilder()->create('public.municipios', function (Blueprint $table) {
        $table->integer('id_muni');
        $table->string('codigo');
        $table->string('nombre');
        $table->string('departamento_codigo');
        $table->primary(['codigo', 'departamento_codigo']);
    });
}

function createDistrictCatalogTable(Connection $database): void
{
    $database->getSchemaBuilder()->create('public.distritos', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->string('nombre');
        $table->string('departamento_codigo');
        $table->integer('municipio_codigo');
    });
}

test('standard catalog options preserve leading zeros and share an ordered code and label format', function () {
    $database = prepareDteCatalogDatabase();
    createDteCatalogTable($database, 'public.CAT-001');
    $database->table('public.CAT-001')->insert([
        ['codigo' => '03', 'valores' => 'Crédito fiscal'],
        ['codigo' => '01', 'valores' => 'Factura'],
    ]);

    $options = Cat001::catalogOptions();

    expect($options->all())->toBe([
        ['codigo' => '01', 'valor' => 'Factura'],
        ['codigo' => '03', 'valor' => 'Crédito fiscal'],
    ]);
    $this->assertDatabaseCount('public.CAT-001', 2, 'dtesantacecilia');
});

test('standard catalogs without records return an empty options collection', function () {
    $database = prepareDteCatalogDatabase();
    createDteCatalogTable($database, 'public.CAT-001');

    $options = Cat001::catalogOptions();

    expect($options->all())->toBe([]);
});

test('identical catalog rows appear once in options without deleting source records', function () {
    $database = prepareDteCatalogDatabase();
    createDteCatalogTable($database, 'public.CAT-027');
    $database->table('public.CAT-027')->insert([
        ['codigo' => '85', 'valores' => 'Descripción repetida'],
        ['codigo' => '85', 'valores' => 'Descripción repetida'],
    ]);

    $options = Cat027::catalogOptions();

    expect($options->all())->toBe([['codigo' => '85', 'valor' => 'Descripción repetida']]);
    $this->assertDatabaseCount('public.CAT-027', 2, 'dtesantacecilia');
});

test('a catalog code with conflicting descriptions produces an explicit error', function () {
    $database = prepareDteCatalogDatabase();
    createDteCatalogTable($database, 'public.CAT-020');
    $database->table('public.CAT-020')->insert([
        ['codigo' => 'AL', 'valores' => 'Primera descripción'],
        ['codigo' => 'AL', 'valores' => 'Otra descripción'],
    ]);

    expect(fn () => Cat020::catalogOptions())->toThrow(
        UnexpectedValueException::class,
        'El catálogo App\\Models\\Dte\\Cat020 contiene códigos con descripciones diferentes: AL.',
    );
    $this->assertDatabaseCount('public.CAT-020', 2, 'dtesantacecilia');
});

test('municipality options use names and filter repeated codes by department', function () {
    $database = prepareDteCatalogDatabase();
    createMunicipalityCatalogTable($database);
    $database->table('public.municipios')->insert([
        ['id_muni' => 1, 'codigo' => '01', 'nombre' => 'Zeta', 'departamento_codigo' => '01'],
        ['id_muni' => 2, 'codigo' => '02', 'nombre' => 'Alfa', 'departamento_codigo' => '01'],
        ['id_muni' => 3, 'codigo' => '01', 'nombre' => 'Otro departamento', 'departamento_codigo' => '02'],
    ]);

    $options = Municipio::catalogOptions('01');

    expect($options->all())->toBe([
        ['codigo' => '02', 'valor' => 'Alfa'],
        ['codigo' => '01', 'valor' => 'Zeta'],
    ]);
});

test('municipalities can be retrieved by their numeric identifier instead of an ambiguous municipal code', function () {
    $database = prepareDteCatalogDatabase();
    createMunicipalityCatalogTable($database);
    $database->table('public.municipios')->insert([
        ['id_muni' => 7, 'codigo' => '01', 'nombre' => 'Primer municipio', 'departamento_codigo' => '01'],
        ['id_muni' => 8, 'codigo' => '01', 'nombre' => 'Segundo municipio', 'departamento_codigo' => '02'],
    ]);

    $municipality = Municipio::query()->findOrFail(8);

    expect($municipality)->nombre->toBe('Segundo municipio')->departamento_codigo->toBe('02');
});

test('an unknown or empty department never loads all municipalities', function (string $department) {
    $database = prepareDteCatalogDatabase();
    createMunicipalityCatalogTable($database);
    $database->table('public.municipios')->insert([
        'id_muni' => 1, 'codigo' => '01', 'nombre' => 'Municipio de prueba', 'departamento_codigo' => '01',
    ]);

    $options = Municipio::catalogOptions($department);

    expect($options->all())->toBe([]);
})->with(['empty department' => [''], 'unknown department' => ['99'], 'SQL fragment' => ["01' OR 1=1 --"]]);

test('district options filter by both department and municipality and return string identifiers', function () {
    $database = prepareDteCatalogDatabase();
    createDistrictCatalogTable($database);
    $database->table('public.distritos')->insert([
        ['id' => 7, 'nombre' => 'Zeta', 'departamento_codigo' => '01', 'municipio_codigo' => 1],
        ['id' => 8, 'nombre' => 'Alfa', 'departamento_codigo' => '01', 'municipio_codigo' => 1],
        ['id' => 9, 'nombre' => 'Otro municipio', 'departamento_codigo' => '01', 'municipio_codigo' => 2],
        ['id' => 10, 'nombre' => 'Otro departamento', 'departamento_codigo' => '02', 'municipio_codigo' => 1],
    ]);

    $options = Distrito::catalogOptions('01', '01');

    expect($options->all())->toBe([
        ['codigo' => '8', 'valor' => 'Alfa'],
        ['codigo' => '7', 'valor' => 'Zeta'],
    ]);
});

test('districts with the same name remain separate when their identifiers differ', function () {
    $database = prepareDteCatalogDatabase();
    createDistrictCatalogTable($database);
    $database->table('public.distritos')->insert([
        ['id' => 8, 'nombre' => 'Mismo nombre', 'departamento_codigo' => '01', 'municipio_codigo' => 1],
        ['id' => 7, 'nombre' => 'Mismo nombre', 'departamento_codigo' => '01', 'municipio_codigo' => 1],
    ]);

    $options = Distrito::catalogOptions('01', '01');

    expect($options->all())->toBe([
        ['codigo' => '7', 'valor' => 'Mismo nombre'],
        ['codigo' => '8', 'valor' => 'Mismo nombre'],
    ]);
});

test('missing or unknown district filters never load all districts', function (string $department, string $municipality) {
    $database = prepareDteCatalogDatabase();
    createDistrictCatalogTable($database);
    $database->table('public.distritos')->insert([
        'id' => 7, 'nombre' => 'Distrito de prueba', 'departamento_codigo' => '01', 'municipio_codigo' => 1,
    ]);

    $options = Distrito::catalogOptions($department, $municipality);

    expect($options->all())->toBe([]);
})->with([
    'empty department' => ['', '01'],
    'empty municipality' => ['01', ''],
    'other department' => ['99', '01'],
    'other municipality' => ['01', '99'],
]);
