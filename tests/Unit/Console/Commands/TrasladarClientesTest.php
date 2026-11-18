<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.puntoventa' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'logging.default' => 'null',
    ]);
});

afterEach(function () {
    if (app()->bound('clientes.test.original_storage')) {
        $temporary = app()->storagePath();
        app()->useStoragePath(app('clientes.test.original_storage'));
        $resolved = realpath($temporary);
        $prefix = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'santacecilia-clientes-test-';

        if ($resolved !== false && str_starts_with($resolved, $prefix)) {
            File::deleteDirectory($resolved);
        }
    }
});

/** @return array{Connection, Connection} */
function prepareCustomerTransfer(): array
{
    app()->instance('clientes.test.original_storage', app()->storagePath());
    app()->useStoragePath(sys_get_temp_dir().'/santacecilia-clientes-test-'.Str::uuid());
    DB::purge('sqlite');
    DB::purge('puntoventa');
    $source = DB::connection('puntoventa');
    $destination = DB::connection();
    $source->statement("ATTACH DATABASE ':memory:' AS public");
    $destination->statement("ATTACH DATABASE ':memory:' AS libreria");

    $source->getSchemaBuilder()->create('public.nacionalidades', function (Blueprint $table) {
        $table->integer('id_nacionalidad')->primary();
        $table->string('nacionalidad');
    });
    $source->getSchemaBuilder()->create('public.distritos', function (Blueprint $table) {
        $table->integer('distrito_id')->primary();
        $table->string('departamento_codigo');
        $table->string('municipio_codigo');
    });
    $source->getSchemaBuilder()->create('public.clientes', function (Blueprint $table) {
        $table->integer('id')->primary();
        foreach (['nombre', 'direccion', 'telefono1', 'telefono2', 'email', 'r_fiscal', 'nit', 'dui', 'cliente', 'tamaño', 'actividad', 'cta_cte'] as $column) {
            $table->string($column)->nullable();
        }
        $table->integer('id_nacionalidad')->nullable();
        $table->integer('id_distrito')->nullable();
    });
    (require database_path('migrations/2026_10_21_200430_create_clientes_table.php'))->up();
    $source->table('public.nacionalidades')->insert(['id_nacionalidad' => 1, 'nacionalidad' => 'Salvadoreña']);
    $source->table('public.distritos')->insert(['distrito_id' => 7, 'departamento_codigo' => '05', 'municipio_codigo' => '02']);

    return [$source, $destination];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sourceCustomer(array $overrides = []): array
{
    return array_replace([
        'id' => 100,
        'nombre' => '  Cliente   de prueba  ',
        'direccion' => '  Calle de prueba  ',
        'telefono1' => '(503) 2222-3333',
        'telefono2' => null,
        'email' => '  PERSONA@EXAMPLE.TEST  ',
        'r_fiscal' => null,
        'nit' => null,
        'dui' => '01234567-8',
        'id_nacionalidad' => 1,
        'id_distrito' => 7,
        'cliente' => ' s ',
        'tamaño' => 'p',
        'actividad' => null,
        'cta_cte' => ' 11010101 ',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storedCustomer(array $overrides = []): array
{
    return array_replace([
        'id' => 100,
        'nombre' => 'Nombre actualizado en destino',
        'direccion' => 'Dirección actualizada en destino',
        'telefono_1' => '50322223333',
        'telefono_2' => '+503 7777-8888',
        'email' => 'persona@example.test',
        'registro_fiscal' => '12345-6',
        'nit' => '06141234567890',
        'dui' => '012345678',
        'nacionalidad' => 'El Salvador',
        'departamento' => '05',
        'municipio' => '02',
        'distrito' => '7',
        'domiciliado' => true,
        'credito' => false,
        'tamano' => null,
        'tipo_cliente' => 'normal',
        'actividad_economica' => '12345',
        'cuenta_contable' => '11010101',
        'created_at' => '2026-01-01 10:00:00',
        'updated_at' => '2026-01-02 10:00:00',
    ], $overrides);
}

/** @return list<array<string, mixed>> */
function omittedCustomers(): array
{
    $rows = [];
    foreach (File::files(storage_path('app/private/traslados/clientes')) as $file) {
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $rows[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    return $rows;
}

test('simulation validates customers without inserting or changing source records', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer());

    $this->artisan('clientes:trasladar', ['--simular' => true])
        ->expectsOutput('Válidos: 1')
        ->expectsOutput('Omitidos: 0')
        ->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(0);
    expect($source->table('public.clientes')->value('email'))->toBe('  PERSONA@EXAMPLE.TEST  ');
    expect(omittedCustomers())->toBe([]);
});

test('transfer normalizes customer fields and preserves identifiers and location codes', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer());

    $this->artisan('clientes:trasladar')->assertSuccessful();

    $customer = $destination->table('libreria.clientes')->first();
    expect($customer)->id->toBe(100)
        ->nombre->toBe('Cliente de prueba')
        ->direccion->toBe('Calle de prueba')
        ->telefono_1->toBe('2222-3333')
        ->email->toBe('persona@example.test')
        ->dui->toBe('01234567-8')
        ->nacionalidad->toBe('Salvadoreña')
        ->departamento->toBe('05')
        ->municipio->toBe('02')
        ->distrito->toBe('7')
        ->domiciliado->toBe(1)
        ->credito->toBe(1)
        ->tamano->toBe('P')
        ->tipo_cliente->toBe('sujeto excluido')
        ->cuenta_contable->toBe('11010101');
    expect($source->table('public.clientes')->count())->toBe(1);
});

test('both phone fields store eight local digits with a hyphen', function (string $phone) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer(['telefono1' => $phone, 'telefono2' => $phone]));

    $this->artisan('clientes:trasladar')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->first())->telefono_1->toBe('2222-3333')->telefono_2->toBe('2222-3333');
    expect($source->table('public.clientes')->value('telefono1'))->toBe($phone);
})->with(['22223333', '2222-3333', '+503 2222-3333', '(503) 2222-3333', '50322223333']);

test('zero in the second phone is stored as null without rejecting the customer', function (int|string $phone) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer(['telefono2' => $phone]));

    $this->artisan('clientes:trasladar')->expectsOutput('Válidos: 1')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->first())->telefono_1->toBe('2222-3333')->telefono_2->toBeNull();
    expect($source->table('public.clientes')->value('telefono2'))->toBe((string) $phone);
    expect(omittedCustomers())->toBe([]);
})->with([0, '0', ' 0 ']);

test('documents store their requested format while fiscal registration stays without separators', function (?string $nit, ?string $expected) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer(['nit' => $nit, 'dui' => '012345678', 'r_fiscal' => ' 12345-6 ']));

    $this->artisan('clientes:trasladar')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->first())
        ->nit->toBe($expected)->dui->toBe('01234567-8')->registro_fiscal->toBe('123456');
})->with([
    'long NIT' => ['06141234567890', '0614-123456-789-0'],
    'formatted long NIT' => ['0614-123456-789-0', '0614-123456-789-0'],
    'NIT with DUI length' => ['012345678', '01234567-8'],
    'formatted NIT with DUI length' => ['01234567-8', '01234567-8'],
    'optional NIT' => [null, null],
    'NIT placeholder with dots and spaces' => ['    .      .   .', null],
    'single dot NIT' => ['.', null],
]);

test('NIT placeholders do not cause otherwise valid customers to be treated as duplicates', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $placeholder = '    .      .   .';
    $source->table('public.clientes')->insert([
        sourceCustomer(['nit' => $placeholder]),
        sourceCustomer(['id' => 101, 'email' => 'segunda@example.test', 'dui' => '11234567-8', 'nit' => $placeholder]),
    ]);

    $this->artisan('clientes:trasladar', ['--lote' => 1])
        ->expectsOutput('Válidos: 2')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->pluck('nit')->all())->toBe([null, null]);
    expect($source->table('public.clientes')->where('id', 100)->value('nit'))->toBe($placeholder);
    expect(omittedCustomers())->toBe([]);
});

test('invalid customers are listed with their original data without blocking later valid customers', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert([
        sourceCustomer(['email' => null, 'cliente' => 'X', 'id_nacionalidad' => 999]),
        sourceCustomer(['id' => 101]),
    ]);

    $this->artisan('clientes:trasladar', ['--lote' => 1])
        ->expectsOutput('Válidos: 1')->expectsOutput('Omitidos: 1')->assertSuccessful();

    expect($destination->table('libreria.clientes')->pluck('id')->all())->toBe([101]);
    $report = omittedCustomers();
    expect($report)->toHaveCount(1);
    expect($report[0])->toMatchArray(['id_origen' => 100]);
    expect($report[0]['datos_origen']['nombre'])->toBe('  Cliente   de prueba  ');
    expect(implode(' ', $report[0]['motivos']))->toContain('email')->not->toContain('nacionalidad', 'S o N');
});

test('missing or empty nationality defaults to El Salvador without rejecting the customer', function (?int $nationalityId, string $catalogName) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.nacionalidades')->where('id_nacionalidad', 1)->update(['nacionalidad' => $catalogName]);
    $source->table('public.clientes')->insert(sourceCustomer(['id_nacionalidad' => $nationalityId]));

    $this->artisan('clientes:trasladar')->expectsOutput('Válidos: 1')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->value('nacionalidad'))->toBe('El Salvador');
    expect(omittedCustomers())->toBe([]);
})->with([
    'no nationality assigned' => [null, 'Salvadoreña'],
    'nationality absent from catalog' => [999, 'Salvadoreña'],
    'empty catalog name' => [1, '   '],
]);

test('unknown or empty customer flag defaults to no credit without changing the source', function (?string $flag) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer(['cliente' => $flag]));

    $this->artisan('clientes:trasladar')->expectsOutput('Válidos: 1')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->value('credito'))->toBe(0);
    expect($source->table('public.clientes')->value('cliente'))->toBe($flag);
    expect(omittedCustomers())->toBe([]);
})->with([
    'unknown flag' => ['X'],
    'blank flag' => ['   '],
    'missing flag' => [null],
]);

test('duplicates are detected after formatting even across batches', function (string $field, string $value, string $reason) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert([
        sourceCustomer(['nit' => '0614-123456-789-0', 'r_fiscal' => '12345-6']),
        sourceCustomer(array_replace([
            'id' => 101, 'email' => 'segunda@example.test', 'dui' => '11234567-8', 'nit' => '0714-123456-789-0', 'r_fiscal' => '23456-7',
        ], [$field => $value])),
    ]);

    $this->artisan('clientes:trasladar', ['--lote' => 1])
        ->expectsOutput('Válidos: 1')->expectsOutput('Omitidos: 1')->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(1);
    expect(omittedCustomers()[0]['motivos'])->toContain("Duplicado por {$reason}; ya corresponde al cliente 100.");
})->with([
    'email' => ['email', 'persona@example.test', 'email'],
    'dui' => ['dui', '012345678', 'dui'],
    'nit' => ['nit', '06141234567890', 'nit'],
    'registro fiscal' => ['r_fiscal', '123456', 'registro_fiscal'],
]);

test('formatted short NIT still matches an unformatted document already in the destination', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer(['nit' => '012345678']));
    $this->artisan('clientes:trasladar')->assertSuccessful();
    $destination->table('libreria.clientes')->where('id', 100)->update(['nit' => '012345678']);
    $source->table('public.clientes')->insert(sourceCustomer([
        'id' => 101, 'email' => 'segunda@example.test', 'dui' => '11234567-8', 'nit' => '01234567-8',
    ]));

    $this->artisan('clientes:trasladar')->expectsOutput('Válidos: 0')->expectsOutput('Omitidos: 2')->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(1);
    expect(omittedCustomers()[1]['motivos'])->toContain('Duplicado por nit; ya corresponde al cliente 100.');
});

test('rerunning skips existing customers without overwriting changes made in the destination', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer());
    $this->artisan('clientes:trasladar')->assertSuccessful();
    $destination->table('libreria.clientes')->update(['nombre' => 'Nombre actualizado en destino']);

    $this->artisan('clientes:trasladar')
        ->expectsOutput('Válidos: 0')->expectsOutput('Omitidos: 1')->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(1);
    expect($destination->table('libreria.clientes')->value('nombre'))->toBe('Nombre actualizado en destino');
});

test('credit and customer type follow the source flags and the combination of activity and registration', function (string $flag, ?string $activity, ?string $registration, int $credit, string $type) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer(['cliente' => $flag, 'actividad' => $activity, 'r_fiscal' => $registration]));

    $this->artisan('clientes:trasladar')->assertSuccessful();

    expect($destination->table('libreria.clientes')->first())->credito->toBe($credit)->tipo_cliente->toBe($type);
})->with([
    ['S', null, null, 1, 'sujeto excluido'],
    ['N', '12345', null, 0, 'normal'],
    ['S', null, '12345-6', 1, 'normal'],
    ['N', '12345', '12345-6', 0, 'normal'],
]);

test('invalid documents and unsupported company size are rejected for later correction', function (array $override) {
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer($override));

    $this->artisan('clientes:trasladar')->expectsOutput('Omitidos: 1')->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(0);
    expect(omittedCustomers())->toHaveCount(1);
})->with([
    [['dui' => '00000000-0']],
    [['dui' => '12345678']],
    [['dui' => '.']],
    [['nit' => 'documento invalido']],
    [['nit' => '0000-000000-000-0']],
    [['nit' => '00000000-0']],
    [['nit' => '1234567890']],
    [['nit' => '1234567890123']],
    [['nit' => '---']],
    [['r_fiscal' => ' - ']],
    [['telefono1' => '2222333']],
    [['telefono1' => '0']],
    [['telefono1' => '2222-ABCD']],
    [['telefono2' => '+502 2222-3333']],
    [['tamaño' => 'X']],
    [['id_distrito' => 999]],
]);

test('an additional database uniqueness conflict skips the row without aborting previous inserts', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $destination->statement('CREATE UNIQUE INDEX libreria.clientes_phone_test_unique ON clientes(telefono_1)');
    $source->table('public.clientes')->insert([
        sourceCustomer(),
        sourceCustomer(['id' => 101, 'email' => 'segunda@example.test', 'dui' => '11234567-8']),
    ]);

    $this->artisan('clientes:trasladar')->expectsOutput('Omitidos: 1')->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(1);
    expect(omittedCustomers()[0]['motivos'])->toContain('Conflicto con una restricción de unicidad del destino.');
});

test('unexpected database errors roll back the entire transfer', function () {
    [$source, $destination] = prepareCustomerTransfer();
    $destination->statement("CREATE TRIGGER libreria.reject_test_customer BEFORE INSERT ON clientes WHEN NEW.id = 101 BEGIN SELECT RAISE(ABORT, 'forced test error'); END");
    $source->table('public.clientes')->insert([
        sourceCustomer(),
        sourceCustomer(['id' => 101, 'email' => 'segunda@example.test', 'dui' => '11234567-8']),
    ]);

    $this->artisan('clientes:trasladar')->assertFailed();

    expect($destination->table('libreria.clientes')->count())->toBe(0);
    expect($source->table('public.clientes')->count())->toBe(2);
});

test('empty source completes without inserting customers', function () {
    [, $destination] = prepareCustomerTransfer();

    $this->artisan('clientes:trasladar')->expectsOutput('Válidos: 0')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(0);
});

test('invalid batch sizes are rejected before connecting to databases', function () {
    $this->artisan('clientes:trasladar', ['--lote' => 0])->assertFailed();
});

test('existing customer formatting updates only format fields and timestamp without importing source customers', function (string $nit, string $expectedNit) {
    $this->freezeTime();
    [$source, $destination] = prepareCustomerTransfer();
    $source->table('public.clientes')->insert(sourceCustomer(['id' => 999]));
    $destination->table('libreria.clientes')->insert(storedCustomer(['nit' => $nit, 'email' => 'correo pendiente']));
    $before = (array) $destination->table('libreria.clientes')->first();

    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true, '--lote' => 1])
        ->expectsOutput('Actualizados: 1')->expectsOutput('Sin cambios: 0')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect((array) $destination->table('libreria.clientes')->first())->toBe(array_replace($before, [
        'telefono_1' => '2222-3333',
        'telefono_2' => '7777-8888',
        'nit' => $expectedNit,
        'dui' => '01234567-8',
        'registro_fiscal' => '123456',
        'updated_at' => now()->toDateTimeString(),
    ]));
    expect($destination->table('libreria.clientes')->count())->toBe(1);
    expect($source->table('public.clientes')->value('telefono1'))->toBe('(503) 2222-3333');
    expect(omittedCustomers())->toBe([]);
})->with([
    'long NIT' => ['06141234567890', '0614-123456-789-0'],
    'short NIT' => ['012345678', '01234567-8'],
]);

test('existing customer formatting simulation leaves all stored values unchanged and does not need the source connection', function () {
    [, $destination] = prepareCustomerTransfer();
    $destination->table('libreria.clientes')->insert(storedCustomer());
    $before = (array) $destination->table('libreria.clientes')->first();
    DB::purge('puntoventa');
    config(['database.connections.puntoventa' => ['driver' => 'unavailable']]);

    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true, '--simular' => true])
        ->expectsOutput('Por actualizar: 1')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect((array) $destination->table('libreria.clientes')->first())->toBe($before);
    expect(omittedCustomers())->toBe([]);
});

test('repeating existing customer formatting makes no further updates', function () {
    [, $destination] = prepareCustomerTransfer();
    $destination->table('libreria.clientes')->insert(storedCustomer([
        'telefono_2' => null, 'nit' => null, 'dui' => null, 'registro_fiscal' => null,
    ]));
    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true])->assertSuccessful();
    $before = (array) $destination->table('libreria.clientes')->first();

    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true])
        ->expectsOutput('Actualizados: 0')->expectsOutput('Sin cambios: 1')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect((array) $destination->table('libreria.clientes')->first())->toBe($before);
    expect($before['telefono_1'])->toBe('2222-3333');
    expect($before['nit'])->toBeNull();
});

test('invalid existing customers remain untouched and are listed while later customers are formatted', function (bool $simulate) {
    [, $destination] = prepareCustomerTransfer();
    $destination->table('libreria.clientes')->insert([
        storedCustomer(['dui' => '12345678']),
        storedCustomer(['id' => 101, 'email' => 'segunda@example.test']),
    ]);
    $before = (array) $destination->table('libreria.clientes')->where('id', 100)->first();

    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true, '--simular' => $simulate, '--lote' => 1])
        ->expectsOutput(($simulate ? 'Por actualizar: ' : 'Actualizados: ').'1')->expectsOutput('Omitidos: 1')->assertSuccessful();

    expect((array) $destination->table('libreria.clientes')->where('id', 100)->first())->toBe($before);
    expect($destination->table('libreria.clientes')->where('id', 101)->value('telefono_1'))->toBe($simulate ? '50322223333' : '2222-3333');
    $report = omittedCustomers();
    expect($report)->toHaveCount(1);
    expect($report[0])->toMatchArray(['id_destino' => 100, 'datos_destino' => $before]);
    expect($report[0]['motivos'])->toContain('dui tiene un formato no admitido.');
})->with([true, false]);

test('database uniqueness conflicts leave an existing customer unchanged without stopping later updates', function () {
    [, $destination] = prepareCustomerTransfer();
    $destination->table('libreria.clientes')->insert([
        storedCustomer(),
        storedCustomer(['id' => 101, 'email' => 'segunda@example.test', 'nit' => '0614-123456-789-0']),
    ]);
    $destination->statement('CREATE UNIQUE INDEX libreria.clientes_nit_test_unique ON clientes(nit)');
    $before = (array) $destination->table('libreria.clientes')->where('id', 100)->first();

    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true, '--lote' => 1])
        ->expectsOutput('Actualizados: 1')->expectsOutput('Omitidos: 1')->assertSuccessful();

    expect((array) $destination->table('libreria.clientes')->where('id', 100)->first())->toBe($before);
    expect($destination->table('libreria.clientes')->where('id', 101)->value('telefono_1'))->toBe('2222-3333');
    expect(omittedCustomers()[0]['motivos'])->toContain('Conflicto con una restricción de unicidad del destino.');
});

test('unexpected errors roll back all existing customer formatting', function () {
    [, $destination] = prepareCustomerTransfer();
    $destination->table('libreria.clientes')->insert([
        storedCustomer(),
        storedCustomer(['id' => 101, 'email' => 'segunda@example.test']),
    ]);
    $before = $destination->table('libreria.clientes')->orderBy('id')->get()->toArray();
    $destination->statement("CREATE TRIGGER libreria.reject_test_format BEFORE UPDATE ON clientes WHEN NEW.id = 101 BEGIN SELECT RAISE(ABORT, 'forced test error'); END");

    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true, '--lote' => 1])->assertFailed();

    expect($destination->table('libreria.clientes')->orderBy('id')->get()->toArray())->toEqual($before);
});

test('existing customer formatting handles an empty destination', function () {
    [, $destination] = prepareCustomerTransfer();

    $this->artisan('clientes:trasladar', ['--formatear-existentes' => true])
        ->expectsOutput('Actualizados: 0')->expectsOutput('Sin cambios: 0')->expectsOutput('Omitidos: 0')->assertSuccessful();

    expect($destination->table('libreria.clientes')->count())->toBe(0);
});
