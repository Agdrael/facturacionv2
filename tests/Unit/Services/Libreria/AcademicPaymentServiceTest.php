<?php

use App\Services\Libreria\AcademicPaymentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../../../Fixtures/DteDatabase.php';
uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'services.factec.environment' => '01', 'receipts.enabled' => false]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $this->withoutVite();
    $this->travelTo(now('America/El_Salvador')->setDate(2026, 11, 11)->setTime(10, 30));
});

function confirmedAcademicJournal(TestCase $test): stdClass
{
    prepareDteGeneration($test);
    prepareAcademicGeneration();
    $id = $test->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->json('id');
    DB::table('libreria.venta_dtes')->where('id', $id)->update(['transmision_estado' => 'ACEPTADO', 'sello_recepcion' => 'SEAL']);
    foreach ([['101', 'normal', '50'], ['102', 'beca', '25']] as [$student,$funding,$amount]) {
        DB::table('libreria.venta_cuotas')->insert(['venta_id' => 1, 'saldo_id' => $student, 'financiamiento' => $funding, 'carnet_estudiante' => $student, 'anio' => 2025, 'mes' => 11, 'monto' => $amount, 'ambiente' => '01']);
    }

    return DB::table('libreria.venta_dtes')->where('id', $id)->first();
}

test('accepted production DTEs settle each student and funding balance exactly once', function () {
    $journal = confirmedAcademicJournal($this);
    app(AcademicPaymentService::class)->apply($journal);
    app(AcademicPaymentService::class)->apply($journal);
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'pago' => 50, 'saldo' => 0], 'plataforma');
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 102, 'pago_beca' => 25, 'saldo_beca' => 0, 'saldo' => 50], 'plataforma');
    $this->assertDatabaseCount('detalle_pagos', 2, 'plataforma');
    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'estado' => 'FACTURADO']);
});

test('a missing local acknowledgement can be recovered from the committed platform details', function () {
    $journal = confirmedAcademicJournal($this);
    app(AcademicPaymentService::class)->apply($journal);
    DB::table('libreria.venta_dtes')->where('id', $journal->id)->update(['pagos_aplicados_at' => null]);
    app(AcademicPaymentService::class)->apply($journal);
    $this->assertDatabaseCount('detalle_pagos', 2, 'plataforma');
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'pago' => 50, 'saldo' => 0], 'plataforma');
});

test('if any student balance changed the entire platform application rolls back', function () {
    $journal = confirmedAcademicJournal($this);
    DB::connection('plataforma')->table('saldo_pagos')->where('id_saldo', 102)->update(['saldo_beca' => 1]);
    expect(fn () => app(AcademicPaymentService::class)->apply($journal))->toThrow(RuntimeException::class);
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'pago' => 0, 'saldo' => 50], 'plataforma');
    $this->assertDatabaseCount('detalle_pagos', 0, 'plataforma');
    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'estado' => 'GENERADO']);
});

test('test documents and responses without a seal cannot update platform payments', function (string $environment, ?string $seal) {
    $journal = confirmedAcademicJournal($this);
    $doc = json_decode($journal->documento, true);
    $doc['identificacion']['ambiente'] = $environment;
    $journal->documento = json_encode($doc);
    $journal->sello_recepcion = $seal;
    expect(fn () => app(AcademicPaymentService::class)->apply($journal))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('detalle_pagos', 0, 'plataforma');
})->with([['00', 'SEAL'], ['01', null]]);

test('sponsor payments update only the sponsor balance', function () {
    $journal = confirmedAcademicJournal($this);
    DB::table('libreria.venta_cuotas')->where('saldo_id', '102')->update(['financiamiento' => 'bienhechor']);
    DB::connection('plataforma')->table('saldo_pagos')->where('id_saldo', 102)->update(['saldo_bienhechor' => 25, 'pago_bienhechor' => 0]);
    app(AcademicPaymentService::class)->apply($journal);
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 102, 'pago_bienhechor' => 25, 'saldo_bienhechor' => 0, 'saldo_beca' => 25, 'saldo' => 50], 'plataforma');
    $this->assertDatabaseHas('detalle_pagos', ['student_id' => 102, 'pago_bienhechor' => true, 'pago_beca' => false], 'plataforma');
});

test('credit tuition needs its accounts receivable process and cannot clear a student balance directly', function () {
    $journal = confirmedAcademicJournal($this);
    $document = json_decode($journal->documento, true);
    $document['resumen']['condicionOperacion'] = 2;
    $journal->documento = json_encode($document);
    expect(fn () => app(AcademicPaymentService::class)->apply($journal))->toThrow(RuntimeException::class);
    $this->assertDatabaseCount('detalle_pagos', 0, 'plataforma');
});
