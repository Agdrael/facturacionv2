<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\User;
use App\Services\Libreria\TuitionService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Tests\TestCase;

require_once __DIR__.'/../../../Fixtures/AccessDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 28));
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'database.connections.plataforma' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('sqlite');
    DB::purge('plataforma');
});

function prepareTuitionPlatform(): Connection
{
    $catalog = DB::connection();
    $catalog->statement("ATTACH DATABASE ':memory:' AS public");
    $catalog->statement("ATTACH DATABASE ':memory:' AS libreria");
    $catalog->getSchemaBuilder()->create('public.productos', function (Blueprint $table) {
        $table->increments('id_producto');
        foreach (['codigo', 'descrip', 'grupo', 'tipo_prod', 'tipo_prec', 'iva2', 'c_ing', 'unidad'] as $column) {
            $table->string($column)->nullable();
        }
        $table->decimal('p_venta', 11, 6)->nullable();
    });
    $catalog->getSchemaBuilder()->create('public.catalogo_contabilidad', function (Blueprint $table) {
        $table->increments('id_catalogo');
        $table->string('cuenta');
    });
    $catalog->getSchemaBuilder()->create('libreria.servicios_academicos', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('producto_id');
        $table->unsignedInteger('grado_id');
        $table->string('especialidad', 128)->default('');
        $table->string('financiamiento', 20)->default('normal');
        $table->string('concepto', 32)->default('colegiatura');
        $table->timestamps();
        $table->unique(['grado_id', 'especialidad', 'financiamiento', 'concepto'], 'servicios_academicos_asignacion_unique');
    });
    $catalog->table('public.catalogo_contabilidad')->insert(['cuenta' => '7101']);
    $catalog->table('public.productos')->insert(['id_producto' => 501, 'codigo' => 'COL-TEST', 'descrip' => 'Colegiatura',
        'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S', 'tipo_prec' => 'F', 'iva2' => 'E', 'unidad' => '99', 'c_ing' => '7101', 'p_venta' => 0]);
    $catalog->table('libreria.servicios_academicos')->insert(['producto_id' => 501, 'grado_id' => 1, 'especialidad' => 'general',
        'financiamiento' => 'normal', 'concepto' => 'colegiatura']);
    $db = DB::connection('plataforma');
    $db->getSchemaBuilder()->create('students', function (Blueprint $table) {
        $table->integer('student_id')->primary();
        $table->string('first_name');
        $table->string('middle_name')->nullable();
        $table->string('last_name');
        foreach ([53, 275, 335, 276, 277, 307, 299, 300, 336, 301, 302, 308, 303, 304, 337, 305, 306, 309,
            83, 96, 286, 343, 287, 86, 84, 97, 98, 103, 80, 105, 288, 344, 289, 85, 106, 81, 107, 112,
            122, 124, 290, 345, 291, 128, 125, 126, 127, 133] as $number) {
            $table->string('custom_'.$number)->nullable();
        }
    });
    $db->getSchemaBuilder()->create('student_enrollment', function (Blueprint $table) {
        $table->integer('student_id');
        $table->integer('grade_id');
        $table->integer('syear');
        $table->string('especialidad')->nullable();
        $table->string('seccion')->nullable();
    });
    $db->getSchemaBuilder()->create('school_gradelevels', function (Blueprint $table) {
        $table->integer('id')->primary();
        $table->string('title');
    });
    $db->getSchemaBuilder()->create('saldo_pagos', function (Blueprint $table) {
        $table->integer('id_saldo')->primary();
        $table->integer('student_id');
        $table->integer('syear');
        $table->integer('mes');
        $table->string('codigo_barra')->nullable();
        $table->boolean('becado')->nullable();
        foreach (['cuota', 'pago', 'saldo', 'cuota_beca', 'pago_beca', 'saldo_beca', 'cuota_bienhechor', 'pago_bienhechor', 'saldo_bienhechor'] as $column) {
            $table->decimal($column, 12, 4)->nullable();
        }
    });
    $db->table('school_gradelevels')->insert(['id' => 1, 'title' => 'Primer año']);
    $db->table('students')->insert([
        'student_id' => 1001, 'first_name' => 'Ana', 'middle_name' => 'Maria', 'last_name' => 'Prueba',
        'custom_53' => 'Padre', 'custom_275' => 'Responsable uno', 'custom_335' => 'NIT', 'custom_276' => '0614-123456-789-0',
        'custom_277' => 'uno@example.test', 'custom_307' => '||Matricula||Febrero||',
        'custom_299' => 'Madre', 'custom_300' => 'Responsable dos', 'custom_336' => 'DUI', 'custom_301' => '01234567-8',
        'custom_302' => 'dos@example.test', 'custom_308' => '||Marzo||Abril||',
        'custom_303' => 'Encargado (a)', 'custom_304' => 'Responsable tres', 'custom_337' => 'Pasaporte', 'custom_305' => 'ABC123',
        'custom_306' => 'tres@example.test', 'custom_309' => '||Noviembre||',
        'custom_105' => 'Dirección padre', 'custom_106' => '2222-1111', 'custom_81' => '2222-2222', 'custom_107' => '2222-3333',
        'custom_288' => 'La Libertad', 'custom_344' => 'La Libertad Sur', 'custom_289' => 'Santa Tecla',
        'custom_96' => 'Dirección madre', 'custom_84' => '7777-1111', 'custom_97' => '7777-2222', 'custom_98' => '7777-3333',
        'custom_124' => 'Dirección encargado', 'custom_125' => '6666-1111', 'custom_126' => '6666-2222', 'custom_127' => '6666-3333',
    ]);
    $db->table('student_enrollment')->insert(['student_id' => 1001, 'grade_id' => 1, 'syear' => 2025, 'especialidad' => 'General', 'seccion' => 'A']);

    return $db;
}

/** @param array<string, mixed> $overrides */
function tuitionBalance(Connection $db, array $overrides = []): void
{
    $db->table('saldo_pagos')->insert(array_replace([
        'id_saldo' => 1, 'student_id' => 1001, 'syear' => 2025, 'mes' => 1,
        'cuota' => 115, 'pago' => 25, 'saldo' => 80, 'codigo_barra' => 'BAR-1', 'becado' => true,
        'cuota_beca' => 20, 'pago_beca' => 5, 'saldo_beca' => 12.34,
        'cuota_bienhechor' => 30, 'pago_bienhechor' => 10, 'saldo_bienhechor' => 19.99,
    ], $overrides));
}

function authenticateTuitionUser(TestCase $test): void
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $test->actingAs(User::factory()->make(['id' => 1]));
    grantTestRoles(auth()->user());
}

test('tuition fees resolve the configured real product and income account while the amount comes from the students balance', function () {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['mes' => 10]);
    authenticateTuitionUser($this);

    $this->getJson(route('libreria.alumnos.cuotas', ['student' => 1001, 'responsable' => 'clientes_varios']))->assertOk()
        ->assertJsonPath('fees.0.name', 'Colegiatura octubre')->assertJsonPath('fees.0.remaining', 8000)
        ->assertJsonPath('fees.0.canSelect', true)->assertJsonPath('fees.0.configurationError', null)
        ->assertJsonPath('fees.0.product.id', 501)->assertJsonPath('fees.0.product.code', 'COL-TEST')
        ->assertJsonPath('fees.0.product.incomeAccount', '7101')->assertJsonPath('fees.0.product.priceType', 'F')
        ->assertJsonPath('fees.0.product.taxType', 'E')->assertJsonPath('fees.0.product.origin', 'COLEGIATURA');
    $this->assertDatabaseHas('public.productos', ['id_producto' => 501, 'p_venta' => 0]);
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 1, 'saldo' => 80], 'plataforma');
});

test('academic links follow product identifiers when its code and income account are edited', function () {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['mes' => 10]);
    DB::table('public.catalogo_contabilidad')->insert(['cuenta' => '7201']);
    DB::table('public.productos')->where('id_producto', 501)->update(['codigo' => 'CODIGO-NUEVO', 'c_ing' => '7201']);

    $fee = app(TuitionService::class)->fees('1001', 'clientes_varios')['fees'][0];

    expect($fee['product'])->toMatchArray(['id' => 501, 'code' => 'CODIGO-NUEVO', 'incomeAccount' => '7201']);
    expect($fee['remaining'])->toBe(8000);
});

test('software enrollment resolves its explicit academic link regardless of capitalization and spacing', function (string $specialty) {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['mes' => 10]);
    $db->table('student_enrollment')->where('student_id', 1001)->update(['especialidad' => $specialty]);
    DB::table('libreria.servicios_academicos')->insert(['producto_id' => 501, 'grado_id' => 1,
        'especialidad' => 'desarrollo de software', 'financiamiento' => 'normal', 'concepto' => 'colegiatura']);
    authenticateTuitionUser($this);

    $this->getJson(route('libreria.alumnos.cuotas', ['student' => 1001, 'responsable' => 'clientes_varios']))->assertOk()
        ->assertJsonPath('fees.0.canSelect', true)->assertJsonPath('fees.0.configurationError', null)
        ->assertJsonPath('fees.0.product.id', 501)->assertJsonPath('fees.0.product.incomeAccount', '7101')
        ->assertJsonPath('fees.0.remaining', 8000);
})->with(['Desarrollo de Software', ' DESARROLLO   DE SOFTWARE ']);

test('a mismatched grade or specialty never borrows another students configured product', function (array $changes) {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['mes' => 10]);
    $db->table('school_gradelevels')->insert(['id' => 2, 'title' => 'Segundo año']);
    $db->table('student_enrollment')->where('student_id', 1001)->update($changes);

    $fee = app(TuitionService::class)->fees('1001', 'clientes_varios')['fees'][0];

    expect($fee['canSelect'])->toBeFalse()->and($fee['product'])->toBeNull();
    expect($fee['configurationError'])->toContain('Falta configurar');
})->with(['different grade' => [['grade_id' => 2]], 'different specialty' => [['especialidad' => 'Electrónica']]]);

test('invalid academic product configurations keep the fees visible but unselectable', function (array $changes) {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['mes' => 10]);
    DB::table('public.productos')->where('id_producto', 501)->update($changes);

    $fee = app(TuitionService::class)->fees('1001', 'clientes_varios')['fees'][0];

    expect($fee['canSelect'])->toBeFalse()->and($fee['product'])->toBeNull();
    expect($fee['configurationError'])->toBeString()->not->toBeEmpty();
    expect($fee['remaining'])->toBe(8000);
})->with([
    'variable price' => [['tipo_prec' => 'V']], 'taxable' => [['iva2' => 'A']],
    'good' => [['tipo_prod' => 'B']], 'wrong origin' => [['grupo' => 'LIBRERIA']],
    'unknown income account' => [['c_ing' => 'INEXISTENTE']],
]);

test('unconfigured matricula scholarships and benefactors do not inherit the normal tuition account', function (string $responsible, int $month) {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['mes' => $month]);

    $fee = app(TuitionService::class)->fees('1001', $responsible)['fees'][0];

    expect($fee['canSelect'])->toBeFalse()->and($fee['product'])->toBeNull();
    expect($fee['configurationError'])->toContain('Falta configurar');
})->with(['matricula' => ['clientes_varios', 1], 'scholarship' => ['beca', 9], 'benefactor' => ['bienhechor', 9]]);

test('explicit scholarship product links use their own account and still allow past months', function () {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['mes' => 9]);
    DB::table('public.catalogo_contabilidad')->insert(['cuenta' => '7201']);
    DB::table('public.productos')->insert(['id_producto' => 502, 'codigo' => 'BECA-TEST', 'descrip' => 'Colegiatura beca',
        'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S', 'tipo_prec' => 'F', 'iva2' => 'E', 'c_ing' => '7201', 'unidad' => '99']);
    DB::table('libreria.servicios_academicos')->insert(['producto_id' => 502, 'grado_id' => 1, 'especialidad' => 'general', 'financiamiento' => 'beca', 'concepto' => 'colegiatura']);

    $fee = app(TuitionService::class)->fees('1001', 'beca')['fees'][0];

    expect($fee['canSelect'])->toBeTrue();
    expect($fee['product'])->toMatchArray(['id' => 502, 'code' => 'BECA-TEST', 'incomeAccount' => '7201']);
    expect($fee['remaining'])->toBe(1234)->and($fee['month'])->toBe(9);
    expect(app(TuitionService::class)->fees('1001', 'clientes_varios')['fees'])->toBe([]);
});

test('the student search uses last year enrollment and returns paginated public academic data', function () {
    $db = prepareTuitionPlatform();
    $db->table('students')->insert(['student_id' => 1002, 'first_name' => 'Fuera', 'last_name' => 'Prueba']);
    $db->table('student_enrollment')->insert(['student_id' => 1002, 'grade_id' => 1, 'syear' => 2024]);
    authenticateTuitionUser($this);

    $this->getJson(route('libreria.alumnos.index', ['q' => 'ANA Prueba']))->assertOk()
        ->assertJsonPath('total', 1)->assertJsonPath('per_page', 10)
        ->assertJsonPath('data.0.id', '1001')->assertJsonPath('data.0.name', 'Ana Maria Prueba')
        ->assertJsonPath('data.0.schoolYear', 2025)->assertJsonPath('data.0.specialty', 'General')
        ->assertJsonPath('data.0.section', 'A')->assertJsonPath('data.0.grade', 'Primer año')
        ->assertJsonMissingPath('data.0.custom_276');
    $this->getJson(route('libreria.alumnos.index', ['q' => '1001']))->assertOk()->assertJsonPath('total', 1);
    $this->travelTo(now()->setYear(2027));
    $this->getJson(route('libreria.alumnos.index'))->assertOk()->assertJsonPath('total', 0);
});

test('student name searches ignore accents and case across name parts while preserving the original names', function (string $search, array $expectedIds) {
    $db = prepareTuitionPlatform();
    foreach ([
        ['student_id' => 1002, 'first_name' => 'Josue', 'middle_name' => null, 'last_name' => 'Peña'],
        ['student_id' => 1003, 'first_name' => 'Jósue', 'middle_name' => 'Ángel', 'last_name' => 'Peña'],
        ['student_id' => 1004, 'first_name' => 'JOSUÉ', 'middle_name' => 'ÁNGEL', 'last_name' => 'PEÑA'],
        ['student_id' => 1005, 'first_name' => "Josue\u{0301}", 'middle_name' => 'Angel', 'last_name' => "Pen\u{0303}a"],
        ['student_id' => 1006, 'first_name' => 'Jose', 'middle_name' => 'Angel', 'last_name' => 'Peña'],
    ] as $student) {
        $db->table('students')->insert($student);
        $db->table('student_enrollment')->insert(['student_id' => $student['student_id'], 'grade_id' => 1, 'syear' => 2025]);
    }
    authenticateTuitionUser($this);

    $response = $this->getJson(route('libreria.alumnos.index', ['q' => $search]))->assertOk()
        ->assertJsonPath('total', count($expectedIds));

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())->toBe($expectedIds);
    expect(collect($response->json('data'))->firstWhere('id', '1003')['name'])->toBe('Jósue Ángel Peña');
})->with([
    'no accent' => ['Josue', ['1002', '1003', '1004', '1005']],
    'accent on another vowel' => ['Jósue', ['1002', '1003', '1004', '1005']],
    'uppercase accented' => ['JOSUÉ', ['1002', '1003', '1004', '1005']],
    'separate combining accent' => ["Josue\u{0301}", ['1002', '1003', '1004', '1005']],
    'full name without accents' => ['josue angel pena', ['1003', '1004', '1005']],
    'full name with accents' => ['JÓSUE ÁNGEL PEÑA', ['1003', '1004', '1005']],
]);

test('all three billing responsible slots use their explicit role and document type rather than their position', function () {
    prepareTuitionPlatform();
    authenticateTuitionUser($this);

    $this->getJson(route('libreria.alumnos.show', 1001))->assertOk()->assertJsonCount(6, 'responsibles')
        ->assertJsonPath('responsibles.0.id', 'custom_275')->assertJsonPath('responsibles.0.label', 'Padre · Responsable uno')
        ->assertJsonPath('responsibles.0.billing.documentType', '36')->assertJsonPath('responsibles.0.billing.email', 'uno@example.test')
        ->assertJsonPath('responsibles.0.billing.address', 'Dirección padre')
        ->assertJsonPath('responsibles.0.billing.department', 'La Libertad')
        ->assertJsonPath('responsibles.0.billing.municipality', 'La Libertad Sur')
        ->assertJsonPath('responsibles.0.billing.district', 'Santa Tecla')
        ->assertJsonPath('responsibles.0.billing.phone', '2222-1111')->assertJsonPath('responsibles.0.billing.phone2', '2222-2222')
        ->assertJsonPath('responsibles.0.billing.phone3', '2222-3333')
        ->assertJsonPath('responsibles.1.billing.documentType', '13')->assertJsonPath('responsibles.1.billing.address', 'Dirección madre')
        ->assertJsonPath('responsibles.2.id', 'custom_304')->assertJsonPath('responsibles.2.billing.documentType', '03')
        ->assertJsonPath('responsibles.2.billing.document', 'ABC123')->assertJsonPath('responsibles.2.billing.address', 'Dirección encargado')
        ->assertJsonPath('responsibles.4.billing.name', 'Clientes varios');
});

test('unmapped family roles do not borrow another persons contact and missing billing uses clientes varios', function () {
    $db = prepareTuitionPlatform();
    $db->table('students')->where('student_id', 1001)->update([
        'custom_53' => 'Padrastro', 'custom_275' => null, 'custom_276' => null, 'custom_277' => null,
    ]);
    $billing = app(TuitionService::class)->details('1001')['responsibles'][0]['billing'];

    expect($billing['name'])->toBe('Clientes varios')->and($billing['address'])->toBe('')
        ->and($billing['email'])->toBe('')->and($billing['phone'])->toBe('');
});

test('each economic responsible only sees their assigned months', function (string $responsible, array $months) {
    $this->travelTo(now()->setDate(2026, 1, 15));
    $db = prepareTuitionPlatform();
    foreach ([1, 2, 3, 4, 11, 14] as $month) {
        tuitionBalance($db, ['id_saldo' => $month, 'mes' => $month]);
    }
    authenticateTuitionUser($this);

    $response = $this->getJson(route('libreria.alumnos.cuotas', ['student' => 1001, 'responsable' => $responsible]))->assertOk();
    expect(array_column($response->json('fees'), 'month'))->toBe($months);
})->with([
    'first slot' => ['custom_275', [1, 2]],
    'second slot' => ['custom_300', [3, 4]],
    'third slot' => ['custom_304', [11]],
    'all normal payments' => ['clientes_varios', [1, 2, 3, 4, 11, 14]],
]);

test('empty responsible assignments do not expose all normal fees', function () {
    $db = prepareTuitionPlatform();
    $db->table('students')->where('student_id', 1001)->update(['custom_307' => '||']);
    tuitionBalance($db);

    expect(app(TuitionService::class)->fees('1001', 'custom_275')['fees'])->toBe([]);
});

test('the tuition endpoint restricts prior normal fees while scholarships and benefactors are exempt', function (string $responsible, array $expected) {
    $db = prepareTuitionPlatform();
    $db->table('students')->where('student_id', 1001)->update([
        'custom_307' => '||Matricula||Febrero||Septiembre||Octubre||Noviembre||',
        'custom_308' => '||Matricula||Febrero||Septiembre||Octubre||Noviembre||',
        'custom_309' => '||Matricula||Febrero||Septiembre||Octubre||Noviembre||',
    ]);
    foreach ([1, 2, 9, 10, 11, 14, 15, 17, 18] as $month) {
        tuitionBalance($db, ['id_saldo' => $month, 'mes' => $month]);
    }
    authenticateTuitionUser($this);

    $response = $this->getJson(route('libreria.alumnos.cuotas', ['student' => 1001, 'responsable' => $responsible]))->assertOk();

    expect(array_column($response->json('fees'), 'month'))->toBe($expected);
    expect($db->table('saldo_pagos')->count())->toBe(9);
})->with([
    'first responsible' => ['custom_275', [1, 10, 11]],
    'second responsible' => ['custom_300', [1, 10, 11]],
    'third responsible' => ['custom_304', [1, 10, 11]],
    'clientes varios' => ['clientes_varios', [1, 10, 11, 14, 15, 17, 18]],
    'scholarship exemption' => ['beca', [1, 2, 9, 10, 11, 14, 15, 17, 18]],
    'benefactor exemption' => ['bienhechor', [1, 2, 9, 10, 11, 14, 15, 17, 18]],
]);

test('the billing cutoff advances with the calendar month while matricula and other services stay available', function () {
    $db = prepareTuitionPlatform();
    foreach ([1, 2, 9, 10, 11, 14, 15, 17, 18] as $month) {
        tuitionBalance($db, ['id_saldo' => $month, 'mes' => $month]);
    }
    $service = app(TuitionService::class);

    $this->travelTo(now()->setDate(2026, 2, 1)->startOfDay());
    expect(array_column($service->fees('1001', 'clientes_varios')['fees'], 'month'))->toBe([1, 2, 9, 10, 11, 14, 15, 17, 18]);
    $this->travelTo(now()->setDate(2026, 9, 30)->endOfDay());
    expect(array_column($service->fees('1001', 'clientes_varios')['fees'], 'month'))->toBe([1, 9, 10, 11, 14, 15, 17, 18]);
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
    expect(array_column($service->fees('1001', 'clientes_varios')['fees'], 'month'))->toBe([1, 10, 11, 14, 15, 17, 18]);
    $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
    expect(array_column($service->fees('1001', 'clientes_varios')['fees'], 'month'))->toBe([1, 11, 14, 15, 17, 18]);
    $this->travelTo(now()->setDate(2026, 12, 1)->startOfDay());
    expect(array_column($service->fees('1001', 'clientes_varios')['fees'], 'month'))->toBe([1, 14, 15, 17, 18]);
    expect(array_column($service->fees('1001', 'beca')['fees'], 'month'))->toBe([1, 2, 9, 10, 11, 14, 15, 17, 18]);
    expect(array_column($service->fees('1001', 'bienhechor')['fees'], 'month'))->toBe([1, 2, 9, 10, 11, 14, 15, 17, 18]);
});

test('students without assigned economic responsibles keep clientes varios available without empty family options', function () {
    $db = prepareTuitionPlatform();
    $db->table('students')->where('student_id', 1001)->update([
        'custom_53' => null, 'custom_275' => null, 'custom_299' => null, 'custom_300' => null, 'custom_303' => null, 'custom_304' => null,
    ]);

    $responsibles = app(TuitionService::class)->details('1001')['responsibles'];

    expect(array_column($responsibles, 'id'))->toBe(['clientes_varios', 'beca', 'bienhechor'])
        ->and($responsibles[0]['billing']['name'])->toBe('Clientes varios');
});

test('fees use each sources authoritative balance and keep distinct source identifiers without changing the database', function (string $responsible, string $funding, int $quota, int $paid, int $remaining) {
    $db = prepareTuitionPlatform();
    tuitionBalance($db);
    tuitionBalance($db, ['id_saldo' => 2, 'syear' => 2024]);
    tuitionBalance($db, ['id_saldo' => 3, 'student_id' => 1002]);
    $before = $db->table('saldo_pagos')->get()->toJson();

    $result = app(TuitionService::class)->fees('1001', $responsible);

    expect($result['schoolYear'])->toBe(2025)->and($result['fees'])->toHaveCount(1)
        ->and($result['fees'][0])->toMatchArray([
            'id' => $funding.':1', 'balanceId' => '1', 'code' => 'BAR-1', 'name' => 'Matrícula',
            'quota' => $quota, 'paid' => $paid, 'remaining' => $remaining, 'schoolYear' => 2025, 'fundingType' => $funding,
        ])->and($db->table('saldo_pagos')->get()->toJson())->toBe($before);
})->with([
    ['custom_275', 'normal', 11500, 2500, 8000],
    ['beca', 'beca', 2000, 500, 1234],
    ['bienhechor', 'bienhechor', 3000, 1000, 1999],
]);

test('scholarships require the scholarship flag and benefactors require a nonnull quota', function () {
    $db = prepareTuitionPlatform();
    tuitionBalance($db, ['id_saldo' => 14, 'mes' => 14, 'becado' => false, 'cuota_bienhechor' => null]);
    tuitionBalance($db, ['id_saldo' => 15, 'mes' => 15, 'cuota_bienhechor' => 0, 'saldo_bienhechor' => -1]);
    $service = app(TuitionService::class);

    expect(array_column($service->fees('1001', 'beca')['fees'], 'month'))->toBe([15])
        ->and($service->fees('1001', 'bienhechor')['fees'])->toHaveCount(1)
        ->and($service->fees('1001', 'bienhechor')['fees'][0])->toMatchArray(['name' => 'Servicio 15', 'quota' => 0, 'remaining' => 0])
        ->and($service->fees('1001', 'clientes_varios')['fees'][0]['name'])->toBe('Servicio 14');
});

test('academic endpoints require authentication', function () {
    $this->getJson(route('libreria.alumnos.index'))->assertUnauthorized();
    $this->getJson(route('libreria.alumnos.show', 1001))->assertUnauthorized();
    $this->getJson(route('libreria.alumnos.cuotas', ['student' => 1001, 'responsable' => 'beca']))->assertUnauthorized();
});

test('academic endpoints reject invalid search and responsible values and students outside the selected year', function () {
    prepareTuitionPlatform();
    authenticateTuitionUser($this);

    $this->getJson(route('libreria.alumnos.index', ['q' => ['array'], 'page' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['q', 'page']);
    $this->getJson(route('libreria.alumnos.cuotas', ['student' => 1001, 'responsable' => 'custom_276']))->assertUnprocessable()->assertJsonValidationErrors('responsable');
    $this->getJson(route('libreria.alumnos.show', 9999))->assertNotFound();
    $this->travelTo(now()->setYear(2027));
    $this->getJson(route('libreria.alumnos.show', 1001))->assertNotFound();
    $this->getJson(route('libreria.alumnos.cuotas', ['student' => 1001, 'responsable' => 'beca']))->assertNotFound();
});
