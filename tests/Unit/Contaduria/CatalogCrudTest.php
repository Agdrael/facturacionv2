<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\User;
use App\Services\Empleado\EmployeeAccessService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Tests\TestCase;

require_once __DIR__.'/../../Fixtures/AccessDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.dtesantacecilia' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ], 'database.connections.sqlite' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $this->withoutVite();
});

function prepareLegacyCatalogs(): Connection
{
    $db = DB::connection();
    $db->statement("ATTACH DATABASE ':memory:' AS public");
    $db->statement("ATTACH DATABASE ':memory:' AS libreria");
    (require database_path('migrations/2026_10_21_200430_create_clientes_table.php'))->up();
    $schema = $db->getSchemaBuilder();
    $schema->create('public.catalogo_contabilidad', function (Blueprint $table) {
        $table->increments('id_catalogo');
        $table->string('cuenta', 32);
        $table->integer('nivel')->nullable();
        $table->string('terminal', 1)->nullable();
        $table->string('tipo_saldo', 1)->nullable();
        $table->string('descripcion', 100)->nullable();
        $table->string('cuenta2')->nullable();
    });
    $schema->create('public.productos', function (Blueprint $table) {
        $table->increments('id_producto');
        foreach (['codigo', 'descrip', 'abreviado', 'grupo', 'ubicacion', 'ubica2', 'cod_prov', 'tipo_prod',
            'tipo_prec', 'unidad', 'iva2', 'iva', 'c_inv', 'c_gas', 'c_ing', 'libreria',
            'codigo_proveedor', 'yellow_w_descuento', 'procedencia', 'linea'] as $column) {
            $table->string($column)->nullable();
        }
        foreach (['p_costo', 'p_venta', 'cos_consig'] as $column) {
            $table->decimal($column, 26, 6)->nullable();
        }
        foreach (['ex_fisica', 'ex_actual', 'ex_virtual', 'ex_virtcon', 'fis_consig', 'ex_consig', 'ex_minima', 'ex_maxima'] as $column) {
            $table->integer($column)->nullable();
        }
        foreach (['descuento', 'yellow_w', 'servicio'] as $column) {
            $table->boolean($column)->nullable();
        }
        $table->timestamp('fecha_modificacion')->nullable();
    });
    $schema->create('public.catalogo_recibos', function (Blueprint $table) {
        $table->increments('id');
        $table->string('nombre_recibo')->nullable();
        $table->string('cuenta_abono')->nullable();
        $table->integer('precio')->nullable();
    });
    $schema->create('public.clientes', function (Blueprint $table) {
        $table->increments('id');
        foreach (['nombre', 'cliente', 'direccion', 'telefono1', 'telefono2', 'r_fiscal', 'nit', 'dui', 'otro_doc',
            'giro', 'cta_cte', 'cod_emp', 'retiene', 'actividad', 'establecim', 'email', 'depa', 'muni', 'nombre_comercial', 'tamaño'] as $column) {
            $table->string($column)->nullable();
        }
        foreach (['codigo', 'id_nacionalidad', 'id_distrito', 'id_municipio'] as $column) {
            $table->integer($column)->nullable();
        }
        $table->double('saldo')->nullable();
    });
    $schema->create('public.proveedores', function (Blueprint $table) {
        $table->increments('id_proveedor');
        $table->string('nombre_proveedor', 64);
        $table->integer('id_nacionalidad');
        foreach (['n_comercial', 'contacto', 'domiciliado', 'direccion', 'id_departamento', 'municipio',
            'telefono', 'telefono2', 'nit', 'dui', 'r_fiscal', 'giro', 'cuenta', 'tamaño'] as $column) {
            $table->string($column)->nullable();
        }
        $table->integer('codigo_act_economica')->nullable();
        $table->string('codigo_distrito')->nullable();
        $table->string('correo_electronico')->nullable()->unique();
        $table->timestamp('fecha_creacion')->useCurrent();
        $table->timestamp('fecha_actualizacion')->nullable();
    });
    $schema->create('public.recibos', function (Blueprint $table) {
        $table->increments('id');
        $table->string('cuenta_cargo')->nullable();
        $table->string('cuenta_abono')->nullable();
    });
    foreach ([['nacionalidades', 'id_nacionalidad', 'nacionalidad'], ['distritos', 'distrito_id', 'nombre'], ['municipios', 'municipio_id', 'nombre']] as [$name, $key, $label]) {
        $schema->create('public.'.$name, function (Blueprint $table) use ($key, $label) {
            $table->increments($key);
            $table->string($label);
        });
        $db->table('public.'.$name)->insert([$key => 1, $label => $name === 'nacionalidades' ? 'El Salvador' : 'Referencia de prueba']);
    }

    $dte = DB::connection('dtesantacecilia');
    $dte->statement("ATTACH DATABASE ':memory:' AS public");
    foreach (['012', '019'] as $number) {
        $dte->getSchemaBuilder()->create('public.CAT-'.$number, function (Blueprint $table) {
            $table->string('codigo');
            $table->string('valores');
        });
    }
    $dte->table('public.CAT-012')->insert([
        ['codigo' => '05', 'valores' => 'La Libertad'], ['codigo' => '01', 'valores' => 'Otro departamento'],
    ]);
    $dte->table('public.CAT-019')->insert(['codigo' => '12345', 'valores' => 'Actividad de prueba']);
    $dte->getSchemaBuilder()->create('public.municipios', function (Blueprint $table) {
        $table->increments('id_muni');
        $table->string('codigo');
        $table->string('nombre');
        $table->string('departamento_codigo');
    });
    $dte->getSchemaBuilder()->create('public.distritos', function (Blueprint $table) {
        $table->increments('id');
        $table->string('nombre');
        $table->string('departamento_codigo');
        $table->integer('municipio_codigo');
    });
    $dte->table('public.municipios')->insert([
        ['codigo' => '09', 'nombre' => 'La Libertad Sur', 'departamento_codigo' => '05'],
        ['codigo' => '01', 'nombre' => 'Otro municipio', 'departamento_codigo' => '05'],
        ['codigo' => '01', 'nombre' => 'Municipio del otro departamento', 'departamento_codigo' => '01'],
    ]);
    $dte->table('public.distritos')->insert([
        ['id' => 7, 'nombre' => 'Santa Tecla', 'departamento_codigo' => '05', 'municipio_codigo' => 9],
        ['id' => 8, 'nombre' => 'Otro distrito', 'departamento_codigo' => '01', 'municipio_codigo' => 1],
    ]);

    return $db;
}

function authenticateCatalogUser(TestCase $test): void
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $test->actingAs(User::factory()->make(['id' => 1]));
    grantTestRoles(auth()->user());
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function catalogSharedClient(array $overrides = []): array
{
    return array_replace([
        'nombre' => 'Cliente de prueba', 'direccion' => 'Dirección de prueba', 'telefono_1' => '2222-3333',
        'telefono_2' => null, 'email' => 'cliente@example.test', 'nacionalidad' => 'El Salvador',
        'departamento' => '05', 'distrito' => '7', 'municipio' => '09', 'domiciliado' => 1, 'credito' => 0,
        'tipo_cliente' => 'normal', 'tamano' => 'P', 'cuenta_contable' => '11010101',
    ], $overrides);
}

dataset('legacy catalogs', [
    'accounts' => ['cuentas', 'public.catalogo_contabilidad', 'id_catalogo', 'descripcion', ['cuenta' => '7101', 'descripcion' => 'Cuenta de prueba', 'nivel' => 1, 'terminal' => 'T', 'tipo_saldo' => 'D']],
    'products' => ['productos', 'public.productos', 'id_producto', 'descrip', ['codigo' => 'P-01', 'descrip' => 'Producto de prueba', 'tipo_prec' => 'F', 'p_venta' => '4.123456', 'tipo_prod' => 'S']],
    'receipt concepts' => ['recibos', 'public.catalogo_recibos', 'id', 'nombre_recibo', ['nombre_recibo' => 'Recibo de prueba', 'precio' => 10]],
    'clients' => ['clientes', 'libreria.clientes', 'id', 'nombre', catalogSharedClient()],
    'suppliers' => ['proveedores', 'public.proveedores', 'id_proveedor', 'nombre_proveedor', ['nombre_proveedor' => 'Proveedor de prueba', 'id_nacionalidad' => 1, 'correo_electronico' => 'proveedor@example.test']],
]);

dataset('billing parties', [
    'client' => ['clientes', catalogSharedClient(['actividad_economica' => '12345'])],
    'supplier' => ['proveedores', [
        'nombre_proveedor' => 'Proveedor de prueba', 'id_nacionalidad' => 1, 'id_departamento' => '05',
        'codigo_distrito' => 7, 'municipio' => '09', 'codigo_act_economica' => 12345, 'cuenta' => '11010101',
    ]],
]);

test('billing forms load real catalogs and persist the selected codes on create and update', function (string $slug, array $data) {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta de facturación']);
    authenticateCatalogUser($this);
    $table = $slug === 'clientes' ? 'libreria.clientes' : 'public.proveedores';
    $department = $slug === 'clientes' ? 'departamento' : 'id_departamento';
    $district = $slug === 'clientes' ? 'distrito' : 'codigo_distrito';

    $this->get(route('contaduria.catalogos.registros.create', $slug))->assertOk()
        ->assertSee('El Salvador')->assertSee('Actividad de prueba')->assertSee('La Libertad')
        ->assertSee('Cuenta de facturación')->assertSee('name="'.$district.'"', false)
        ->assertViewHas('options', fn (array $options): bool => collect($options['districts'])->firstWhere('value', '7')['municipalityCode'] === '09'
            && in_array(['value' => '11010101', 'label' => 'Cuenta de facturación'], $options['accounts'], true));
    $this->post(route('contaduria.catalogos.registros.store', $slug), $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas($table, $data);

    $this->get(route('contaduria.catalogos.registros.edit', [$slug, 1]))->assertOk()
        ->assertSee('value="12345" selected', false)->assertSee('value="05" selected', false)
        ->assertSee('value="11010101"', false);
    $this->putJson(route('contaduria.catalogos.registros.update', [$slug, 1]), array_replace($data, [$department => '01']))
        ->assertUnprocessable()->assertJsonValidationErrors($district);
    $this->assertDatabaseHas($table, $data);

    $updated = array_replace($data, [$department => '01', $district => '8', 'municipio' => '01']);
    $this->put(route('contaduria.catalogos.registros.update', [$slug, 1]), $updated)->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas($table, $updated);
    $this->assertDatabaseCount($table, 1);
})->with('billing parties');

test('unknown nationality activity and account return 422 without saving billing data', function (string $slug, array $data) {
    prepareLegacyCatalogs();
    authenticateCatalogUser($this);
    $invalid = $slug === 'clientes'
        ? ['nacionalidad' => 'Inexistente', 'actividad_economica' => '99999', 'cuenta_contable' => '99999999']
        : ['id_nacionalidad' => 999, 'codigo_act_economica' => 99999, 'cuenta' => '99999999'];

    $this->postJson(route('contaduria.catalogos.registros.store', $slug), array_replace($data, $invalid))
        ->assertUnprocessable()->assertJsonValidationErrors(array_keys($invalid));

    $this->assertDatabaseCount($slug === 'clientes' ? 'libreria.clientes' : 'public.proveedores', 0);
})->with('billing parties');

test('inconsistent department district and municipality return 422 without saving billing data', function (string $slug, array $data, array $location) {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta de facturación']);
    authenticateCatalogUser($this);
    $department = $slug === 'clientes' ? 'departamento' : 'id_departamento';
    $district = $slug === 'clientes' ? 'distrito' : 'codigo_distrito';
    $invalid = [$department => $location[0], $district => $location[1], 'municipio' => $location[2]];

    $this->postJson(route('contaduria.catalogos.registros.store', $slug), array_replace($data, $invalid))
        ->assertUnprocessable()->assertJsonValidationErrors($district);

    $this->assertDatabaseCount($slug === 'clientes' ? 'libreria.clientes' : 'public.proveedores', 0);
})->with('billing parties')->with([
    'district from another department' => [['01', '7', '09']],
    'municipality from another district' => [['05', '7', '01']],
    'missing district' => [['05', '', '09']],
]);

test('product and receipt account suggestions reject codes outside the accounting catalog', function (string $slug, array $data, string $column) {
    prepareLegacyCatalogs();
    authenticateCatalogUser($this);

    $this->postJson(route('contaduria.catalogos.registros.store', $slug), $data)
        ->assertUnprocessable()->assertJsonValidationErrors($column);

    $this->assertDatabaseCount($slug === 'productos' ? 'public.productos' : 'public.catalogo_recibos', 0);
})->with([
    'product' => ['productos', ['codigo' => 'P1', 'descrip' => 'Producto', 'c_ing' => '99999999'], 'c_ing'],
    'receipt concept' => ['recibos', ['nombre_recibo' => 'Concepto', 'cuenta_abono' => '99999999'], 'cuenta_abono'],
]);

test('a municipality code existing only in another department cannot validate an orphan district', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta de facturación']);
    $dte = DB::connection('dtesantacecilia');
    $dte->table('public.municipios')->insert(['codigo' => '02', 'nombre' => 'Municipio ajeno', 'departamento_codigo' => '01']);
    $dte->table('public.distritos')->insert(['id' => 9, 'nombre' => 'Distrito sin municipio', 'departamento_codigo' => '05', 'municipio_codigo' => 2]);
    authenticateCatalogUser($this);

    $this->postJson(route('contaduria.catalogos.registros.store', 'clientes'), catalogSharedClient(['distrito' => '9', 'municipio' => '02']))
        ->assertUnprocessable()->assertJsonValidationErrors('distrito');

    $this->assertDatabaseCount('libreria.clientes', 0);
});

test('catalog cards list the five confirmed legacy catalogs with their record counts', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_recibos')->insert(['nombre_recibo' => 'Concepto de prueba']);
    authenticateCatalogUser($this);

    $response = $this->get(route('contaduria.catalogos'))->assertOk()->assertViewHas('counts', fn (array $counts): bool => $counts['recibos'] === 1);
    foreach (['Catálogo contable', 'Catálogo de productos', 'Catálogo de recibos', 'Catálogo de clientes', 'Catálogo de proveedores'] as $title) {
        $response->assertSee($title);
    }
});

test('each catalog renders its real listing and forms and can create update and delete its own table', function (string $slug, string $table, string $key, string $label, array $data) {
    $db = prepareLegacyCatalogs();
    if ($slug === 'clientes') {
        $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta de clientes']);
    }
    authenticateCatalogUser($this);
    $this->get(route('contaduria.catalogos.registros.index', $slug))->assertOk()->assertSee('Este catálogo aún no tiene registros.');
    $this->get(route('contaduria.catalogos.registros.create', $slug))->assertOk()->assertSee('Crear registro');

    $this->post(route('contaduria.catalogos.registros.store', $slug), $data + ['id' => 999, 'id_producto' => 999, 'id_proveedor' => 999, 'id_catalogo' => 999, 'table' => 'public.recibos'])
        ->assertRedirectToRoute('contaduria.catalogos.registros.index', $slug)->assertSessionHas('status');
    $row = $db->table($table)->sole();
    expect($row->{$key})->toBe(1)->and($row->{$label})->toBe($data[$label]);
    expect($db->table('public.recibos')->count())->toBe(0);
    $this->get(route('contaduria.catalogos.registros.index', $slug))->assertOk()->assertSee($data[$label]);
    $this->get(route('contaduria.catalogos.registros.edit', [$slug, 1]))->assertOk()->assertSee('Guardar cambios')->assertSee($data[$label]);

    $this->put(route('contaduria.catalogos.registros.update', [$slug, 1]), array_replace($data, [$label => 'Registro actualizado']))
        ->assertRedirectToRoute('contaduria.catalogos.registros.index', $slug);
    expect($db->table($table)->where($key, 1)->value($label))->toBe('Registro actualizado');
    $this->delete(route('contaduria.catalogos.registros.destroy', [$slug, 1]))->assertRedirectToRoute('contaduria.catalogos.registros.index', $slug);
    expect($db->table($table)->count())->toBe(0);
})->with('legacy catalogs');

test('required identifying fields reject empty writes without changing the catalog', function (string $slug, string $table) {
    $db = prepareLegacyCatalogs();
    authenticateCatalogUser($this);
    $this->postJson(route('contaduria.catalogos.registros.store', $slug), [])->assertUnprocessable()->assertJsonValidationErrors(match ($slug) {
        'cuentas' => ['cuenta', 'descripcion'], 'productos' => ['codigo', 'descrip'], 'recibos' => ['nombre_recibo'],
        'clientes' => ['nombre'], 'proveedores' => ['nombre_proveedor', 'id_nacionalidad'],
    });
    expect($db->table($table)->count())->toBe(0);
})->with('legacy catalogs');

test('catalog searches ignore accents and case and retain stable pagination', function () {
    $db = prepareLegacyCatalogs();
    foreach (range(1, 22) as $id) {
        $db->table('libreria.clientes')->insert(catalogSharedClient(['id' => $id, 'nombre' => 'Jósue Ángel', 'email' => 'cliente'.$id.'@example.test']));
    }
    $db->table('libreria.clientes')->insert(catalogSharedClient(['id' => 23, 'nombre' => 'Otra persona', 'email' => 'otra@example.test']));
    authenticateCatalogUser($this);

    $this->get(route('contaduria.catalogos.registros.index', ['catalog' => 'clientes', 'q' => 'JOSUE angel', 'page' => 2]))->assertOk()
        ->assertViewHas('records', fn ($records): bool => $records->total() === 22 && $records->count() === 2 && $records->first()->getKey() === 21);
    $this->get(route('contaduria.catalogos.registros.index', ['catalog' => 'clientes', 'q' => "' OR 1=1 --"]))->assertOk()->assertViewHas('records', fn ($records): bool => $records->total() === 0);
    $this->get(route('contaduria.catalogos.registros.index', ['catalog' => 'clientes', 'q' => '%']))->assertOk()->assertViewHas('records', fn ($records): bool => $records->total() === 0);
});

test('catalog records escape stored html in lists and edit forms', function () {
    $db = prepareLegacyCatalogs();
    $db->table('libreria.clientes')->insert(catalogSharedClient(['nombre' => '<img src=x onerror=alert(1)>']));
    authenticateCatalogUser($this);
    $this->get(route('contaduria.catalogos.registros.index', 'clientes'))->assertOk()->assertSee('&lt;img', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
    $this->get(route('contaduria.catalogos.registros.edit', ['clientes', 1]))->assertOk()->assertSee('&lt;img', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
});

test('catalog updates preserve inventory quantities and unrelated legacy client balances', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta de clientes']);
    $db->table('public.productos')->insert(['codigo' => 'P1', 'descrip' => 'Producto', 'ex_actual' => 17]);
    $db->table('public.clientes')->insert(['nombre' => 'Cliente', 'saldo' => 90.5]);
    $db->table('libreria.clientes')->insert(catalogSharedClient());
    authenticateCatalogUser($this);

    $this->put(route('contaduria.catalogos.registros.update', ['productos', 1]), ['codigo' => 'P1', 'descrip' => 'Producto', 'ex_actual' => 0, 'p_venta' => '4.123456'])->assertRedirect();
    $this->put(route('contaduria.catalogos.registros.update', ['clientes', 1]), catalogSharedClient(['nombre' => 'Cliente actualizado', 'saldo' => 0]))->assertRedirect();
    expect($db->table('public.productos')->value('ex_actual'))->toBe(17)->and($db->table('public.productos')->value('p_venta'))->toEqual(4.123456)
        ->and($db->table('public.productos')->value('fecha_modificacion'))->not->toBeNull()
        ->and($db->table('public.clientes')->value('saldo'))->toEqual(90.5);
    expect($db->table('libreria.clientes')->value('nombre'))->toBe('Cliente actualizado');
});

test('supplier validation checks existing nationalities and unique email while allowing its current email on edit', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.proveedores')->insert(['nombre_proveedor' => 'Existente', 'id_nacionalidad' => 1, 'correo_electronico' => 'uno@example.test']);
    authenticateCatalogUser($this);

    $this->postJson(route('contaduria.catalogos.registros.store', 'proveedores'), ['nombre_proveedor' => 'Nuevo', 'id_nacionalidad' => 999, 'correo_electronico' => 'uno@example.test'])
        ->assertUnprocessable()->assertJsonValidationErrors(['id_nacionalidad', 'correo_electronico']);
    $this->put(route('contaduria.catalogos.registros.update', ['proveedores', 1]), ['nombre_proveedor' => 'Actualizado', 'id_nacionalidad' => 1, 'correo_electronico' => 'uno@example.test'])->assertRedirect();
    expect($db->table('public.proveedores')->count())->toBe(1)->and($db->table('public.proveedores')->value('nombre_proveedor'))->toBe('Actualizado');
});

test('prices precision field lengths and enum values are validated before writing', function () {
    $db = prepareLegacyCatalogs();
    authenticateCatalogUser($this);
    $this->postJson(route('contaduria.catalogos.registros.store', 'productos'), ['codigo' => str_repeat('x', 65), 'descrip' => 'Producto', 'p_venta' => '100000', 'p_costo' => '-1', 'procedencia' => 'otra'])
        ->assertUnprocessable()->assertJsonValidationErrors(['codigo', 'p_venta', 'p_costo', 'procedencia']);
    $this->postJson(route('contaduria.catalogos.registros.store', 'recibos'), ['nombre_recibo' => 'Recibo', 'precio' => '1.25'])->assertUnprocessable()->assertJsonValidationErrors('precio');
    expect($db->table('public.productos')->count())->toBe(0)->and($db->table('public.catalogo_recibos')->count())->toBe(0);
});

test('products use their type for goods and services and ignore the unused service flag', function (string $type, string $label, string $nextType) {
    $db = prepareLegacyCatalogs();
    authenticateCatalogUser($this);

    $this->get(route('contaduria.catalogos.registros.create', 'productos'))->assertOk()
        ->assertSee('name="tipo_prod"', false)->assertSee('Servicio')->assertSee('Bien')
        ->assertDontSee('name="servicio"', false);
    $this->post(route('contaduria.catalogos.registros.store', 'productos'), ['codigo' => 'P1', 'descrip' => 'Producto', 'tipo_prod' => $type, 'servicio' => 1])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('public.productos', ['codigo' => 'P1', 'tipo_prod' => $type, 'servicio' => null]);
    $this->get(route('contaduria.catalogos.registros.index', 'productos'))->assertOk()->assertSee($label);

    $db->table('public.productos')->where('id_producto', 1)->update(['servicio' => true]);
    $this->put(route('contaduria.catalogos.registros.update', ['productos', 1]), ['codigo' => 'P1', 'descrip' => 'Actualizado', 'tipo_prod' => $nextType, 'servicio' => 0])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('public.productos', ['id_producto' => 1, 'tipo_prod' => $nextType, 'servicio' => 1]);
})->with([
    'service' => ['S', 'Servicio', 'B'],
    'good' => ['B', 'Bien', 'S'],
]);

test('obsolete product types return 422 without changing data on create and update', function (string $type, string $method) {
    $db = prepareLegacyCatalogs();
    $db->table('public.productos')->insert(['codigo' => 'P1', 'descrip' => 'Original', 'tipo_prod' => 'B']);
    authenticateCatalogUser($this);
    $route = $method === 'POST' ? route('contaduria.catalogos.registros.store', 'productos')
        : route('contaduria.catalogos.registros.update', ['productos', 1]);

    $this->json($method, $route, ['codigo' => $method === 'POST' ? 'P2' : 'P1', 'descrip' => 'Cambio', 'tipo_prod' => $type])
        ->assertUnprocessable()->assertJsonValidationErrors('tipo_prod')
        ->assertJsonPath('errors.tipo_prod.0', 'Selecciona una opción válida para Tipo de producto.');

    $this->assertDatabaseCount('public.productos', 1);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'P1', 'descrip' => 'Original', 'tipo_prod' => 'B']);
})->with(['lowercase b' => ['b'], 'old CS' => ['CS']])->with(['create' => ['POST'], 'update' => ['PUT']]);

test('product forms use the origin enum provider accounts and only the supported tax classifications', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.proveedores')->insert([
        ['nombre_proveedor' => 'Proveedor uno', 'id_nacionalidad' => 1, 'cuenta' => '2101'],
        ['nombre_proveedor' => 'Proveedor dos', 'id_nacionalidad' => 1, 'cuenta' => '2101'],
        ['nombre_proveedor' => 'Sin cuenta', 'id_nacionalidad' => 1, 'cuenta' => null],
    ]);
    authenticateCatalogUser($this);

    $this->get(route('contaduria.catalogos.registros.create', 'productos'))->assertOk()
        ->assertSee('Origen')->assertSee('COLEGIATURA')->assertSee('Cuenta contable del proveedor')
        ->assertSee('Afecta')->assertSee('Exenta')->assertSee('Precio de venta sin IVA')->assertSee('Precio de venta con IVA')
        ->assertDontSee('name="iva"', false)->assertDontSee('name="codigo_proveedor"', false)
        ->assertDontSee('name="p_venta_con_iva"', false)->assertDontSee('Indicador de IVA')
        ->assertViewHas('options', fn (array $options): bool => $options['supplierAccounts'] === [
            ['value' => '2101', 'label' => 'Proveedor dos / Proveedor uno'],
        ]);
});

test('products save the selected origin provider account and net price while ignoring unused and helper fields', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.proveedores')->insert(['nombre_proveedor' => 'Proveedor', 'id_nacionalidad' => 1, 'cuenta' => '2101']);
    authenticateCatalogUser($this);
    $data = ['codigo' => 'P1', 'descrip' => 'Producto', 'grupo' => 'LIBRERIA', 'cod_prov' => '2101', 'iva2' => 'A',
        'p_venta' => '3.672566', 'p_venta_con_iva' => '4.15', 'iva' => '1', 'codigo_proveedor' => 'No guardar'];

    $this->post(route('contaduria.catalogos.registros.store', 'productos'), $data)->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('public.productos', ['codigo' => 'P1', 'grupo' => 'LIBRERIA', 'cod_prov' => '2101', 'iva2' => 'A',
        'p_venta' => '3.672566', 'iva' => null, 'codigo_proveedor' => null]);

    $db->table('public.productos')->where('id_producto', 1)->update(['iva' => '0', 'codigo_proveedor' => 'Valor antiguo']);
    $this->put(route('contaduria.catalogos.registros.update', ['productos', 1]), array_replace($data, ['grupo' => 'CFP', 'iva2' => 'E', 'p_venta' => '10']))
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('public.productos', ['id_producto' => 1, 'grupo' => 'CFP', 'iva2' => 'E', 'p_venta' => 10,
        'iva' => '0', 'codigo_proveedor' => 'Valor antiguo']);
});

test('unsupported product origin tax classification or provider account return 422 on create and update', function (string $column, string $value, string $method) {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '9999', 'descripcion' => 'Cuenta sin proveedor']);
    $db->table('public.productos')->insert(['codigo' => 'P1', 'descrip' => 'Original', 'grupo' => 'LIBRERIA', 'iva2' => 'A']);
    authenticateCatalogUser($this);
    $route = $method === 'POST' ? route('contaduria.catalogos.registros.store', 'productos')
        : route('contaduria.catalogos.registros.update', ['productos', 1]);
    $data = ['codigo' => $method === 'POST' ? 'P2' : 'P1', 'descrip' => 'Cambio', 'grupo' => 'LIBRERIA', 'iva2' => 'A'];

    $this->json($method, $route, array_replace($data, [$column => $value]))->assertUnprocessable()->assertJsonValidationErrors($column);

    $this->assertDatabaseCount('public.productos', 1);
    $this->assertDatabaseHas('public.productos', ['codigo' => 'P1', 'descrip' => 'Original', 'grupo' => 'LIBRERIA', 'iva2' => 'A']);
})->with([
    'unknown origin' => ['grupo', 'OTRO'],
    'unsupported tax class' => ['iva2', 'N'],
    'account without supplier' => ['cod_prov', '9999'],
])->with(['create' => ['POST'], 'update' => ['PUT']]);

test('duplicate product codes return 422 without changing records on create or update', function (string $method) {
    $db = prepareLegacyCatalogs();
    $db->table('public.productos')->insert([
        ['id_producto' => 17, 'codigo' => 'P-001', 'descrip' => 'Primer producto'],
        ['id_producto' => 42, 'codigo' => 'P-002', 'descrip' => 'Segundo producto'],
    ]);
    authenticateCatalogUser($this);
    $route = $method === 'POST'
        ? route('contaduria.catalogos.registros.store', 'productos')
        : route('contaduria.catalogos.registros.update', ['productos', 17]);

    $this->json($method, $route, ['codigo' => 'P-002', 'descrip' => 'Duplicado'])
        ->assertUnprocessable()->assertJsonValidationErrors('codigo')
        ->assertJsonPath('errors.codigo.0', 'Ya existe un registro con ese valor de Código.');

    $this->assertDatabaseCount('public.productos', 2);
    $this->assertDatabaseHas('public.productos', ['id_producto' => 17, 'codigo' => 'P-001', 'descrip' => 'Primer producto']);
    $this->assertDatabaseHas('public.productos', ['id_producto' => 42, 'codigo' => 'P-002', 'descrip' => 'Segundo producto']);
})->with(['create' => ['POST'], 'update' => ['PUT']]);

test('products can retain their current code or change to an unused code when editing', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.productos')->insert(['id_producto' => 17, 'codigo' => 'P-001', 'descrip' => 'Original']);
    authenticateCatalogUser($this);

    $this->put(route('contaduria.catalogos.registros.update', ['productos', 17]), ['codigo' => 'P-001', 'descrip' => 'Actualizado'])
        ->assertRedirectToRoute('contaduria.catalogos.registros.index', 'productos')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('public.productos', ['id_producto' => 17, 'codigo' => 'P-001', 'descrip' => 'Actualizado']);

    $this->put(route('contaduria.catalogos.registros.update', ['productos', 17]), ['codigo' => 'P-003', 'descrip' => 'Nuevo código'])
        ->assertRedirectToRoute('contaduria.catalogos.registros.index', 'productos')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('public.productos', 1);
    $this->assertDatabaseHas('public.productos', ['id_producto' => 17, 'codigo' => 'P-003', 'descrip' => 'Nuevo código']);
});

test('duplicate account codes return 422 without changing records on create or update', function (string $method) {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert([
        ['cuenta' => '7101', 'descripcion' => 'Primera cuenta'],
        ['cuenta' => '7201', 'descripcion' => 'Segunda cuenta'],
    ]);
    authenticateCatalogUser($this);
    $route = $method === 'POST'
        ? route('contaduria.catalogos.registros.store', 'cuentas')
        : route('contaduria.catalogos.registros.update', ['cuentas', 1]);

    $this->json($method, $route, ['cuenta' => '7201', 'descripcion' => 'Duplicada'])
        ->assertUnprocessable()->assertJsonValidationErrors('cuenta')
        ->assertJsonPath('errors.cuenta.0', 'Ya existe un registro con ese valor de Cuenta.');

    $this->assertDatabaseCount('public.catalogo_contabilidad', 2);
    $this->assertDatabaseHas('public.catalogo_contabilidad', ['id_catalogo' => 1, 'cuenta' => '7101', 'descripcion' => 'Primera cuenta']);
    $this->assertDatabaseHas('public.catalogo_contabilidad', ['id_catalogo' => 2, 'cuenta' => '7201', 'descripcion' => 'Segunda cuenta']);
})->with(['create' => ['POST'], 'update' => ['PUT']]);

test('successful catalog writes redirect to an automatically opened confirmation dialog', function () {
    prepareLegacyCatalogs();
    authenticateCatalogUser($this);

    $this->followingRedirects()->post(route('contaduria.catalogos.registros.store', 'productos'), ['codigo' => 'P1', 'descrip' => 'Producto', 'tipo_prod' => 'B'])
        ->assertOk()->assertSee('data-modal="catalog-feedback"', false)->assertSee('x-init="$nextTick(', false)
        ->assertSee('Operación completada')->assertSee('Registro creado correctamente.')->assertSee('Entendido');

    $this->assertDatabaseHas('public.productos', ['codigo' => 'P1', 'tipo_prod' => 'B']);
});

test('blocked catalog deletions redirect to an automatically opened error dialog without deleting data', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '7101', 'descripcion' => 'Cuenta en uso']);
    $db->table('public.productos')->insert(['codigo' => 'P1', 'descrip' => 'Producto', 'c_ing' => '7101']);
    authenticateCatalogUser($this);

    $this->from(route('contaduria.catalogos.registros.index', 'cuentas'))->followingRedirects()
        ->delete(route('contaduria.catalogos.registros.destroy', ['cuentas', 1]))
        ->assertOk()->assertSee('data-modal="catalog-feedback"', false)->assertSee('x-init="$nextTick(', false)
        ->assertSee('No se pudo completar la acción')->assertSee('Esta cuenta está en uso y no se puede eliminar.')
        ->assertDontSee('Operación completada');

    $this->assertDatabaseHas('public.catalogo_contabilidad', ['cuenta' => '7101']);
    $this->assertDatabaseCount('public.productos', 1);
});

test('product form and pricing help are provided in dialogs opened by explicit help buttons', function () {
    prepareLegacyCatalogs();
    authenticateCatalogUser($this);

    $this->get(route('contaduria.catalogos.registros.create', 'productos'))->assertOk()
        ->assertSee('Ayuda del formulario')->assertSee('Información de precios e IVA')
        ->assertSee('data-modal="catalog-form-help"', false)->assertSee('data-modal="product-price-help"', false)
        ->assertDontSee('x-init="$nextTick(', false);
});

test('validation errors render an automatically opened dialog and preserve the entered data', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '7101', 'descripcion' => 'Existente']);
    authenticateCatalogUser($this);

    $this->from(route('contaduria.catalogos.registros.create', 'cuentas'))->followingRedirects()
        ->post(route('contaduria.catalogos.registros.store', 'cuentas'), ['cuenta' => '7101', 'descripcion' => 'Datos ingresados'])
        ->assertOk()
        ->assertSee('data-modal="catalog-validation"', false)
        ->assertSee("\$flux.modal('catalog-validation').show()", false)
        ->assertSee('No se guardaron los cambios')
        ->assertSee('Ya existe un registro con ese valor de Cuenta.')
        ->assertSee('Revisar formulario')
        ->assertSee('Datos ingresados');

    $this->assertDatabaseCount('public.catalogo_contabilidad', 1);
});

test('unused accounts can retain their code or change to a code not held by another record', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '7101', 'descripcion' => 'Original']);
    authenticateCatalogUser($this);

    $this->put(route('contaduria.catalogos.registros.update', ['cuentas', 1]), ['cuenta' => '7101', 'descripcion' => 'Actualizada'])->assertRedirect();
    $this->assertDatabaseHas('public.catalogo_contabilidad', ['id_catalogo' => 1, 'cuenta' => '7101', 'descripcion' => 'Actualizada']);

    $this->put(route('contaduria.catalogos.registros.update', ['cuentas', 1]), ['cuenta' => '7301', 'descripcion' => 'Nueva cuenta'])->assertRedirect();
    $this->assertDatabaseCount('public.catalogo_contabilidad', 1);
    $this->assertDatabaseHas('public.catalogo_contabilidad', ['id_catalogo' => 1, 'cuenta' => '7301', 'descripcion' => 'Nueva cuenta']);
});

test('accounts in use cannot be deleted or renamed but their description can be edited', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '7101', 'descripcion' => 'Original']);
    $db->table('public.productos')->insert(['codigo' => 'P1', 'descrip' => 'Producto', 'c_ing' => '7101']);
    authenticateCatalogUser($this);
    $this->deleteJson(route('contaduria.catalogos.registros.destroy', ['cuentas', 1]))->assertUnprocessable()->assertJsonValidationErrors('catalog');
    $this->putJson(route('contaduria.catalogos.registros.update', ['cuentas', 1]), ['cuenta' => '7201', 'descripcion' => 'Cambio'])->assertUnprocessable()->assertJsonValidationErrors('cuenta');
    expect($db->table('public.catalogo_contabilidad')->value('cuenta'))->toBe('7101')->and($db->table('public.catalogo_contabilidad')->value('descripcion'))->toBe('Original');
    $this->put(route('contaduria.catalogos.registros.update', ['cuentas', 1]), ['cuenta' => '7101', 'descripcion' => 'Nueva descripción'])->assertRedirect();
    expect($db->table('public.catalogo_contabilidad')->value('descripcion'))->toBe('Nueva descripción');
});

test('foreign key references prevent deleting products and leave all records intact', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.productos')->insert(['codigo' => 'P1', 'descrip' => 'Producto']);
    $db->getSchemaBuilder()->create('public.catalog_test_refs', function (Blueprint $table) {
        $table->integer('producto_id');
        $table->foreign('producto_id')->references('id_producto')->on('productos');
    });
    $db->table('public.catalog_test_refs')->insert(['producto_id' => 1]);
    authenticateCatalogUser($this);

    $this->deleteJson(route('contaduria.catalogos.registros.destroy', ['productos', 1]))->assertUnprocessable()->assertJsonValidationErrors('catalog');
    expect($db->table('public.productos')->count())->toBe(1)->and($db->table('public.catalog_test_refs')->count())->toBe(1);
});

test('guest users cannot read or mutate catalogs', function () {
    $this->getJson(route('contaduria.catalogos'))->assertUnauthorized();
    $this->getJson(route('contaduria.catalogos.registros.index', 'clientes'))->assertUnauthorized();
    $this->postJson(route('contaduria.catalogos.registros.store', 'clientes'), ['nombre' => 'No permitido'])->assertUnauthorized();
    $this->putJson(route('contaduria.catalogos.registros.update', ['clientes', 1]), ['nombre' => 'No permitido'])->assertUnauthorized();
    $this->deleteJson(route('contaduria.catalogos.registros.destroy', ['clientes', 1]))->assertUnauthorized();
});

test('authenticated users without employee access cannot write catalog data', function () {
    $db = prepareLegacyCatalogs();
    $this->withoutMiddleware([ValidateSessionWithWorkOS::class, ResolvePuntoVentaById::class]);
    $this->actingAs(User::factory()->make(['id' => 1]));
    $this->mock(EmployeeAccessService::class)->shouldReceive('employeeFor')->once()->andReturnNull();

    $this->post(route('contaduria.catalogos.registros.store', 'clientes'), ['nombre' => 'No permitido'])->assertRedirectToRoute('employee-access.rejected');
    expect($db->table('libreria.clientes')->count())->toBe(0);
});

test('contaduria manages the same clients exposed to libreria without writing to the old client table', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta de clientes']);
    $db->table('public.clientes')->insert(['nombre' => 'Cliente antiguo', 'email' => 'antiguo@example.test']);
    authenticateCatalogUser($this);

    $this->post(route('contaduria.catalogos.registros.store', 'clientes'), catalogSharedClient(['tipo_cliente' => 'empresa', 'tamano' => 'M']))->assertRedirect();
    $this->getJson(route('libreria.clientes.index', ['q' => 'Cliente de prueba']))->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1)->assertJsonPath('data.0.email', 'cliente@example.test')
        ->assertJsonPath('data.0.phone', '2222-3333');
    $this->get(route('contaduria.catalogos.registros.edit', ['clientes', 1]))->assertOk()
        ->assertSee('name="telefono_1"', false)->assertSee('value="empresa" selected', false)->assertSee('value="M" selected', false);

    $this->put(route('contaduria.catalogos.registros.update', ['clientes', 1]), catalogSharedClient(['nombre' => 'Cliente compartido', 'email' => 'compartido@example.test']))->assertRedirect();
    $this->getJson(route('libreria.clientes.index', ['q' => 'Cliente compartido']))->assertOk()->assertJsonPath('data.0.email', 'compartido@example.test');
    expect($db->table('public.clientes')->sole()->nombre)->toBe('Cliente antiguo')
        ->and($db->table('libreria.clientes')->count())->toBe(1);
});

test('shared client email uniqueness is enforced while the current email can be retained', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta de clientes']);
    $db->table('libreria.clientes')->insert(catalogSharedClient());
    authenticateCatalogUser($this);
    $this->postJson(route('contaduria.catalogos.registros.store', 'clientes'), catalogSharedClient(['nombre' => 'Duplicado']))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->put(route('contaduria.catalogos.registros.update', ['clientes', 1]), catalogSharedClient(['nombre' => 'Actualizado']))->assertRedirect();
    expect($db->table('libreria.clientes')->count())->toBe(1)->and($db->table('libreria.clientes')->value('nombre'))->toBe('Actualizado');
});

test('shared clients referenced by sales cannot be deleted from contaduria', function () {
    $db = prepareLegacyCatalogs();
    $db->table('libreria.clientes')->insert(catalogSharedClient());
    $db->getSchemaBuilder()->create('libreria.ventas', function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedBigInteger('cliente_id');
        $table->foreign('cliente_id')->references('id')->on('clientes');
    });
    $db->table('libreria.ventas')->insert(['cliente_id' => 1]);
    authenticateCatalogUser($this);
    $this->deleteJson(route('contaduria.catalogos.registros.destroy', ['clientes', 1]))->assertUnprocessable()->assertJsonValidationErrors('catalog');
    expect($db->table('libreria.clientes')->count())->toBe(1)->and($db->table('libreria.ventas')->count())->toBe(1);
});

test('accounts referenced only by the shared clients remain protected', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.catalogo_contabilidad')->insert(['cuenta' => '11010101', 'descripcion' => 'Cuenta compartida']);
    $db->table('libreria.clientes')->insert(catalogSharedClient());
    authenticateCatalogUser($this);
    $this->deleteJson(route('contaduria.catalogos.registros.destroy', ['cuentas', 1]))->assertUnprocessable()->assertJsonValidationErrors('catalog');
    expect($db->table('public.catalogo_contabilidad')->count())->toBe(1);
});

test('receipt concepts are managed without changing issued receipts', function () {
    $db = prepareLegacyCatalogs();
    $db->table('public.recibos')->insert(['cuenta_cargo' => '1101', 'cuenta_abono' => '7101']);
    authenticateCatalogUser($this);
    $this->post(route('contaduria.catalogos.registros.store', 'recibos'), ['nombre_recibo' => 'Concepto', 'precio' => 10])->assertRedirect();
    $this->put(route('contaduria.catalogos.registros.update', ['recibos', 1]), ['nombre_recibo' => 'Concepto actualizado', 'precio' => 12])->assertRedirect();
    $this->delete(route('contaduria.catalogos.registros.destroy', ['recibos', 1]))->assertRedirect();
    expect($db->table('public.catalogo_recibos')->count())->toBe(0)
        ->and($db->table('public.recibos')->sole()->cuenta_abono)->toBe('7101');
});

test('unknown catalogs missing records and invalid searches are rejected', function () {
    prepareLegacyCatalogs();
    authenticateCatalogUser($this);
    $this->getJson('/contaduria/catalogos/users')->assertNotFound();
    $this->postJson('/contaduria/catalogos/users', ['nombre' => 'No permitido'])->assertNotFound();
    $this->getJson(route('contaduria.catalogos.registros.edit', ['clientes', 999]))->assertNotFound();
    $this->putJson(route('contaduria.catalogos.registros.update', ['clientes', 999]), ['nombre' => 'No permitido'])->assertNotFound();
    $this->deleteJson(route('contaduria.catalogos.registros.destroy', ['clientes', 999]))->assertNotFound();
    $this->getJson(route('contaduria.catalogos.registros.index', ['catalog' => 'clientes', 'q' => ['array'], 'page' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['q', 'page']);
});
