<?php

use App\Models\User;
use App\Services\Libreria\AcademicPaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../../../../Fixtures/DteDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'services.factec.username' => 'test-user', 'services.factec.password' => 'test-password', 'services.factec.base_url' => 'https://factec.example.test',
        'services.factec.environment' => '00', 'services.factec.transmission_enabled' => true]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $this->withoutVite();
});

function acceptedSaleForInvalidation(TestCase $test, string $environment = '00', string $type = '01', string $productType = 'B'): int
{
    config(['services.factec.environment' => $environment]);
    $test->travelTo(now('America/El_Salvador')->setDate(2026, 11, 11)->setTime(10, 30));
    Http::preventStrayRequests();
    prepareDteGeneration($test);
    DB::table('public.productos')->update(['tipo_prod' => $productType]);
    $remote = DB::connection('dtesantacecilia');
    $remote->statement('CREATE TABLE public."CAT-024" (codigo TEXT PRIMARY KEY, valores TEXT)');
    $remote->table('public.CAT-024')->insert([['codigo' => '1', 'valores' => 'Error en la información'], ['codigo' => '2', 'valores' => 'Rescindir de la operación'], ['codigo' => '3', 'valores' => 'Otros']]);
    DB::table('inv.inv_cat_doc_tipo')->insert(['doc_tipo' => 'AJP', 'activo' => true]);
    DB::table('inv.inv_cat_tipo_mov')->insert(['tipo_mov' => 'AJUSTE_POS', 'afecta_existencia' => true, 'afecta_cpp_global' => false]);
    $input = dteSalePayload();
    $input['document']['type'] = $type;
    if ($type === '03') {
        DB::table('libreria.clientes')->insert(['id' => 2, 'nombre' => 'Cliente fiscal', 'credito' => false, 'cuenta_contable' => null]);
        $input['client']['id'] = 2;
        $input['client'] = [...$input['client'], 'documentType' => '36', 'document' => '0614-000000-000-0', 'nrc' => '1234567', 'activity' => '12345', 'department' => '05', 'municipality' => '01', 'district' => '96', 'address' => 'Dirección de prueba'];
    }
    $id = $test->postJson(route('libreria.ventas.store'), $input)->assertCreated()->json('id');
    DB::table('libreria.venta_dtes')->where('id', $id)->update(['transmision_estado' => $environment === '00' ? 'ACEPTADO_PRUEBA' : 'ACEPTADO', 'sello_recepcion' => str_repeat('A', 40)]);
    $remote->table('public.dte_json')->update(['procesado' => true, 'respuesta_hacienda' => 'Respuesta original conservada']);

    return $id;
}

/** @return array<string, mixed> */
function invalidationInput(): array
{
    return ['reasonType' => '2', 'reason' => 'El cliente devolvió los productos.', 'replacement' => null,
        'responsibleName' => 'Operador de prueba', 'responsibleType' => '13', 'responsibleDocument' => '00000000-0',
        'requesterName' => 'Solicitante de prueba', 'requesterType' => '13', 'requesterDocument' => '11111111-1', 'confirmed' => true];
}

function prepareInvalidationEvent(TestCase $test, int $id): stdClass
{
    $test->postJson(route('libreria.ventas.invalidacion.store', $id), invalidationInput())->assertCreated()->assertJsonPath('invalidationState', 'PENDIENTE');

    return DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $id)->first();
}

function fakeInvalidationAcceptance(stdClass $event, string $environment = '00'): void
{
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['token' => 'test-token']),
        'https://factec.example.test/api/invalidacion/' => Http::response(['status' => 'OK', 'body' => ['estado' => 'PROCESADO',
            'codigoGeneracion' => $event->codigo_generacion, 'ambiente' => $environment, 'selloRecibido' => str_repeat('B', 40)]])]);
}

test('invalidation endpoints require an authenticated session', function () {
    $this->getJson(route('libreria.ventas.invalidacion.show', 1))->assertUnauthorized();
    $this->postJson(route('libreria.ventas.invalidacion.store', 1), invalidationInput())->assertUnauthorized();
    $this->postJson(route('libreria.ventas.invalidacion.transmit', 1))->assertUnauthorized();
});

test('an operator cannot access or invalidate another operators DTE', function () {
    $id = acceptedSaleForInvalidation($this);
    $this->actingAs(User::factory()->make(['id' => 88]));
    grantTestRoles(auth()->user(), ['libreria']);

    $this->getJson(route('libreria.ventas.invalidacion.show', $id))->assertForbidden();
    $this->postJson(route('libreria.ventas.invalidacion.store', $id), invalidationInput())->assertForbidden();
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertForbidden();
    $this->getJson(route('libreria.ventas.invalidacion.download', $id))->assertForbidden();

    $this->assertDatabaseCount('libreria.venta_invalidaciones', 0);
    Http::assertNothingSent();
});

test('the invalidation permission gate is enforced even for the original operator', function () {
    $id = acceptedSaleForInvalidation($this);
    Gate::define('libreria.ventas.invalidar', fn (): bool => false);

    $this->postJson(route('libreria.ventas.invalidacion.store', $id), invalidationInput())->assertForbidden();
    $this->getJson(route('libreria.ventas.history'))->assertOk()->assertJsonPath('data.0.canInvalidate', false);

    Http::assertNothingSent();
});

test('admin and contador can invalidate another operators DTE and the event records the acting user', function (string $role) {
    $id = acceptedSaleForInvalidation($this);
    $actor = User::factory()->make(['id' => 88, 'email' => 'responsable@example.test']);
    grantTestRoles($actor, [$role]);
    $this->actingAs($actor);

    $this->getJson(route('libreria.ventas.history'))->assertOk()->assertJsonPath('data.0.canInvalidate', true)->assertJsonPath('data.0.canManage', false);
    $this->getJson(route('libreria.ventas.invalidacion.show', $id))->assertOk();
    $event = prepareInvalidationEvent($this, $id);
    $this->assertDatabaseHas('libreria.venta_invalidaciones', ['venta_dte_id' => $id, 'user_id' => 88]);
    $this->get(route('libreria.ventas.invalidacion.download', $id))->assertOk();
    fakeInvalidationAcceptance($event);
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);
    $this->assertDatabaseHas('libreria.venta_dtes', ['id' => $id, 'user_id' => 7]);
    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'estado' => 'ANULADO']);
    Http::assertSentCount(2);
})->with(['admin', 'contador']);

test('a librarian cannot invalidate their own accepted DTE', function () {
    $id = acceptedSaleForInvalidation($this);
    $otherAdmin = User::factory()->make(['id' => 88]);
    grantTestRoles($otherAdmin, ['admin']);
    grantTestRoles(auth()->user(), ['libreria']);

    $this->getJson(route('libreria.ventas.history'))->assertOk()->assertJsonPath('data.0.canInvalidate', false);
    $this->getJson(route('libreria.ventas.invalidacion.show', $id))->assertForbidden();
    $this->postJson(route('libreria.ventas.invalidacion.store', $id), invalidationInput())->assertForbidden();
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertForbidden();
    $this->get(route('libreria.ventas.invalidacion.download', $id))->assertForbidden();
    $this->assertDatabaseCount('libreria.venta_invalidaciones', 0);
    Http::assertNothingSent();
});

test('preparing an event copies its JSON into the original external row without annulling or transmitting', function () {
    $id = acceptedSaleForInvalidation($this);
    $original = DB::connection('dtesantacecilia')->table('public.dte_json')->value('resultado');

    $event = prepareInvalidationEvent($this, $id);
    $json = json_decode($event->documento, true);
    $this->getJson(route('libreria.ventas.invalidacion.show', $id))->assertOk()->assertJsonPath('event.invalidationState', 'PENDIENTE')->assertJsonPath('reasonTypes.1.codigo', '2');
    $this->get(route('libreria.ventas.invalidacion.download', $id))->assertOk()->assertJsonPath('identificacion.version', 3)->assertJsonPath('documento.tipoDte', '01');

    expect($json['identificacion'])->toMatchArray(['version' => 3, 'ambiente' => '00', 'fecEmi' => '2026-11-11', 'fusion' => null]);
    expect($json['documento'])->toMatchArray(['selloRecibido' => str_repeat('A', 40), 'codigoGeneracionR' => null]);
    expect($json['identificacion']['codigoGeneracion'])->not->toBe($json['documento']['codigoGeneracion']);
    $this->assertDatabaseHas('public.dte_json', ['resultado' => $original, 'json_invalid' => $event->documento, 'invalidado' => false, 'respuesta_hacienda' => 'Respuesta original conservada'], 'dtesantacecilia');
    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'estado' => 'GENERADO']);
    $this->assertDatabaseCount('inv.inv_documentos', 1);
    Http::assertNothingSent();
});

test('invalidating an unconfirmed or mismatched document never creates an event', function (array $changes) {
    $id = acceptedSaleForInvalidation($this);
    DB::table('libreria.venta_dtes')->where('id', $id)->update($changes);

    $this->postJson(route('libreria.ventas.invalidacion.store', $id), invalidationInput())->assertUnprocessable()->assertJsonValidationErrors('api');

    $this->assertDatabaseCount('libreria.venta_invalidaciones', 0);
    Http::assertNothingSent();
})->with([[['transmision_estado' => 'PENDIENTE']], [['transmision_estado' => 'REVISION']], [['sello_recepcion' => null]], [['sello_recepcion' => 'short']], [['transmision_estado' => 'ACEPTADO']]]);

test('the invalidation request validates its reason identities and explicit confirmation', function (array $changes, string $field) {
    $id = acceptedSaleForInvalidation($this);

    $this->postJson(route('libreria.ventas.invalidacion.store', $id), array_replace(invalidationInput(), $changes))->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('libreria.venta_invalidaciones', 0);
    Http::assertNothingSent();
})->with([
    [['reasonType' => '9'], 'reasonType'], [['reason' => ''], 'reason'], [['reason' => str_repeat('A', 201)], 'reason'],
    [['responsibleType' => '99'], 'responsibleType'], [['responsibleDocument' => ''], 'responsibleDocument'],
    [['requesterName' => ''], 'requesterName'], [['requesterDocument' => ''], 'requesterDocument'], [['confirmed' => false], 'confirmed'],
    [['reasonType' => '1'], 'replacement'], [['replacement' => (string) Str::uuid()], 'replacement'],
]);

test('repeated preparation is idempotent and cannot overwrite an existing event with different data', function () {
    $id = acceptedSaleForInvalidation($this);
    $event = prepareInvalidationEvent($this, $id);

    $this->postJson(route('libreria.ventas.invalidacion.store', $id), invalidationInput())->assertCreated()->assertJsonPath('uuid', $event->codigo_generacion);
    $this->postJson(route('libreria.ventas.invalidacion.store', $id), array_replace(invalidationInput(), ['reason' => 'Otra explicación diferente']))->assertUnprocessable();

    $this->assertDatabaseCount('libreria.venta_invalidaciones', 1);
    Http::assertNothingSent();
});

test('accepted invalidations mark the original external row and reverse inventory once preserving ownership', function () {
    $id = acceptedSaleForInvalidation($this);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('invalidationState', 'ACEPTADO_PRUEBA')->assertJsonPath('applied', true);
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'estado' => 'ANULADO']);
    $this->assertDatabaseHas('public.dte_json', ['invalidado' => true, 'json_invalid' => $event->documento, 'respuesta_hacienda' => 'Respuesta original conservada'], 'dtesantacecilia');
    $this->assertDatabaseCount('inv.inv_documentos', 2);
    $this->assertDatabaseHas('inv.inv_documentos', ['doc_tipo' => 'AJP', 'doc_numero' => $event->codigo_generacion, 'tipo_mov' => 'AJUSTE_POS']);
    $this->assertDatabaseHas('inv.inv_documentos_det', ['tipo_mov' => 'AJUSTE_POS', 'propiedad' => 'CONSIGNADO', 'cantidad' => 3, 'bodega_id' => 10]);
    $this->assertDatabaseHas('inv.inv_documentos_det', ['tipo_mov' => 'AJUSTE_POS', 'propiedad' => 'PROPIO', 'cantidad' => 2, 'bodega_id' => 10]);
    $this->getJson(route('libreria.ventas.history'))->assertOk()->assertJsonPath('data.0.status', 'Anulada');
    $this->postJson(route('libreria.ventas.transmit', $id))->assertUnprocessable()->assertJsonValidationErrors('api');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://factec.example.test/api/invalidacion/' && $request['version'] === 3 && $request['ambiente'] === '00'
        && $request['codigo_generacion'] === $event->codigo_generacion && $request['json_data']['documento']['selloRecibido'] === str_repeat('A', 40));
});

test('invalidation preserves the snapshot UUID casing when PostgreSQL stores the UUID in lowercase', function () {
    $id = acceptedSaleForInvalidation($this);
    $journal = DB::table('libreria.venta_dtes')->where('id', $id)->first();
    $originalUuid = json_decode($journal->documento, true)['identificacion']['codigoGeneracion'];
    $databaseUuid = strtolower($originalUuid);
    DB::table('libreria.venta_dtes')->where('id', $id)->update(['codigo_generacion' => $databaseUuid]);
    DB::connection('dtesantacecilia')->table('public.dte_json')->where('dte_id', $originalUuid)->update(['dte_id' => $databaseUuid]);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://factec.example.test/api/invalidacion/' && $request['json_data']['documento']['codigoGeneracion'] === $originalUuid);
    Http::assertSentCount(2);
    $this->assertDatabaseHas('libreria.venta_dtes', ['id' => $id, 'codigo_generacion' => $databaseUuid]);
    $this->assertDatabaseHas('libreria.ventas', ['estado' => 'ANULADO']);
    $this->assertDatabaseCount('inv.inv_documentos', 2);
});

test('rejection uncertain results and different environments never reverse the sale or resend', function (array $body, string $state) {
    $id = acceptedSaleForInvalidation($this);
    prepareInvalidationEvent($this, $id);
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['token' => 'test-token']), 'https://factec.example.test/api/invalidacion/' => Http::response($body)]);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('invalidationState', $state)->assertJsonPath('applied', false);
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('invalidationState', $state);

    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'estado' => 'GENERADO']);
    $this->assertDatabaseHas('public.dte_json', ['invalidado' => false], 'dtesantacecilia');
    $this->assertDatabaseCount('inv.inv_documentos', 1);
    Http::assertSentCount(2);
})->with([
    [['status' => 'ERROR', 'body' => ['estado' => 'RECHAZADO', 'descripcionMsg' => 'Fuera de plazo']], 'RECHAZADO'],
    [['status' => 'OK'], 'REVISION'],
    [['status' => 'OK', 'body' => ['ambiente' => '01', 'estado' => 'PROCESADO', 'selloRecibido' => 'wrong']], 'REVISION'],
]);

test('a lost invalidation response persists its attempt and prevents another POST', function () {
    $id = acceptedSaleForInvalidation($this);
    prepareInvalidationEvent($this, $id);
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['token' => 'test-token']), 'https://factec.example.test/api/invalidacion/' => Http::failedConnection()]);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('invalidationState', 'REVISION');
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', false);

    expect(DB::table('libreria.venta_invalidaciones')->value('intento_at'))->not->toBeNull();
    $this->assertDatabaseCount('inv.inv_documentos', 1);
    Http::assertSentCount(2);
});

test('the disabled connector leaves the prepared event pending without authenticating', function () {
    $id = acceptedSaleForInvalidation($this);
    prepareInvalidationEvent($this, $id);
    config(['services.factec.transmission_enabled' => false]);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertUnprocessable()->assertJsonValidationErrors('api');

    $this->assertDatabaseHas('libreria.venta_invalidaciones', ['estado' => 'PENDIENTE', 'intento_at' => null]);
    Http::assertNothingSent();
});

test('a late remote response reconciles an interrupted invalidation without sending it again', function () {
    $id = acceptedSaleForInvalidation($this);
    $event = prepareInvalidationEvent($this, $id);
    DB::table('libreria.venta_invalidaciones')->where('id', $event->id)->update(['estado' => 'ENVIANDO']);
    DB::connection('dtesantacecilia')->table('public.dte_json')->update(['respuesta_invalid' => json_encode(['status' => 'OK', 'body' => [
        'estado' => 'PROCESADO', 'codigoGeneracion' => $event->codigo_generacion, 'ambiente' => '00', 'selloRecibido' => str_repeat('B', 40)]])]);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    $this->assertDatabaseHas('public.dte_json', ['invalidado' => true], 'dtesantacecilia');
    $this->assertDatabaseHas('libreria.ventas', ['estado' => 'ANULADO']);
    Http::assertNothingSent();
});

test('an accepted invalidation with failed local reversal can reconcile without retransmission', function () {
    $id = acceptedSaleForInvalidation($this);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event);
    DB::table('inv.inv_cat_tipo_mov')->where('tipo_mov', 'AJUSTE_POS')->delete();

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('invalidationState', 'ACEPTADO_PRUEBA')->assertJsonPath('applied', false);
    $this->assertDatabaseHas('libreria.ventas', ['estado' => 'GENERADO']);
    DB::table('inv.inv_cat_tipo_mov')->insert(['tipo_mov' => 'AJUSTE_POS', 'afecta_existencia' => true, 'afecta_cpp_global' => false]);
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    $this->assertDatabaseCount('inv.inv_documentos', 2);
    Http::assertSentCount(2);
});

test('productive invalidation restores only the original amounts for several children and funding sources', function () {
    $id = acceptedSaleForInvalidation($this, '01');
    prepareAcademicGeneration();
    DB::connection('plataforma')->table('saldo_pagos')->where('id_saldo', 101)->update(['cuota_bienhechor' => 25, 'pago_bienhechor' => 10, 'saldo_bienhechor' => 15]);
    foreach ([['101', 'normal', '50'], ['102', 'beca', '25'], ['101', 'bienhechor', '15']] as [$student, $funding, $amount]) {
        DB::table('libreria.venta_cuotas')->insert(['venta_id' => 1, 'saldo_id' => $student, 'financiamiento' => $funding, 'carnet_estudiante' => $student, 'anio' => 2025, 'mes' => 11, 'monto' => $amount, 'ambiente' => '01']);
    }
    $journal = DB::table('libreria.venta_dtes')->where('id', $id)->first();
    app(AcademicPaymentService::class)->apply($journal);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event, '01');

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'pago' => 0, 'saldo' => 50, 'pago_bienhechor' => 10, 'saldo_bienhechor' => 15], 'plataforma');
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 102, 'pago_beca' => 0, 'saldo_beca' => 25], 'plataforma');
    expect(DB::connection('plataforma')->table('detalle_pagos')->where('estado', 'Anulado')->count())->toBe(3);
    expect(DB::table('libreria.venta_cuotas')->where('reserva_activa', true)->count())->toBe(0);
    $this->assertDatabaseCount('libreria.venta_cuotas', 3);
    Http::assertSentCount(2);
});

test('test invalidations do not write to the academic platform even when they contain quota references', function () {
    $id = acceptedSaleForInvalidation($this);
    DB::table('libreria.venta_cuotas')->insert(['venta_id' => 1, 'saldo_id' => '101', 'financiamiento' => 'normal', 'carnet_estudiante' => '101', 'anio' => 2025, 'mes' => 11, 'monto' => 50, 'ambiente' => '00']);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event);
    config(['database.connections.plataforma' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    DB::purge('plataforma');

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    $this->assertDatabaseHas('libreria.venta_cuotas', ['id' => 1, 'reserva_activa' => null]);
    Http::assertSentCount(2);
});

test('a platform reversal committed before a local failure is not applied twice on reconciliation', function () {
    $id = acceptedSaleForInvalidation($this, '01');
    prepareAcademicGeneration();
    DB::table('libreria.venta_cuotas')->insert(['venta_id' => 1, 'saldo_id' => '101', 'financiamiento' => 'normal', 'carnet_estudiante' => '101', 'anio' => 2025, 'mes' => 11, 'monto' => 50, 'ambiente' => '01']);
    app(AcademicPaymentService::class)->apply(DB::table('libreria.venta_dtes')->where('id', $id)->first());
    DB::table('libreria.venta_dtes')->where('id', $id)->update(['pagos_aplicados_at' => null]);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event, '01');
    DB::table('inv.inv_cat_tipo_mov')->where('tipo_mov', 'AJUSTE_POS')->delete();

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', false);
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'saldo' => 50, 'pago' => 0], 'plataforma');
    $this->assertDatabaseHas('libreria.venta_cuotas', ['id' => 1, 'reserva_activa' => true]);
    DB::table('inv.inv_cat_tipo_mov')->insert(['tipo_mov' => 'AJUSTE_POS', 'afecta_existencia' => true, 'afecta_cpp_global' => false]);
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'saldo' => 50, 'pago' => 0], 'plataforma');
    $this->assertDatabaseCount('detalle_pagos', 1, 'plataforma');
    $this->assertDatabaseHas('libreria.venta_cuotas', ['id' => 1, 'reserva_activa' => null]);
    $this->assertDatabaseCount('inv.inv_documentos', 2);
    Http::assertSentCount(2);
});

test('a changed academic balance rolls back all student reversals and keeps the sale pending reconciliation', function () {
    $id = acceptedSaleForInvalidation($this, '01');
    prepareAcademicGeneration();
    foreach ([101, 102] as $student) {
        DB::table('libreria.venta_cuotas')->insert(['venta_id' => 1, 'saldo_id' => (string) $student, 'financiamiento' => 'normal', 'carnet_estudiante' => (string) $student, 'anio' => 2025, 'mes' => 11, 'monto' => 50, 'ambiente' => '01']);
    }
    app(AcademicPaymentService::class)->apply(DB::table('libreria.venta_dtes')->where('id', $id)->first());
    DB::connection('plataforma')->table('saldo_pagos')->where('id_saldo', 102)->update(['pago' => 1]);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event, '01');

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('invalidationState', 'ACEPTADO')->assertJsonPath('applied', false);

    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'saldo' => 0, 'pago' => 50], 'plataforma');
    expect(DB::connection('plataforma')->table('detalle_pagos')->where('estado', 'Anulado')->count())->toBe(0);
    $this->assertDatabaseHas('libreria.ventas', ['estado' => 'FACTURADO']);
    $this->assertDatabaseCount('inv.inv_documentos', 1);
    Http::assertSentCount(2);
});

test('an invalidated quota can be reserved again while its previous references remain in the audit trail', function () {
    $id = acceptedSaleForInvalidation($this);
    $claim = ['venta_id' => 1, 'saldo_id' => '101', 'financiamiento' => 'normal', 'carnet_estudiante' => '101', 'anio' => 2025, 'mes' => 11, 'monto' => 50, 'ambiente' => '00'];
    DB::table('libreria.venta_cuotas')->insert($claim);
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event);
    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);
    $input = dteSalePayload();
    $input['requestKey'] = (string) Str::uuid();
    $this->postJson(route('libreria.ventas.store'), $input)->assertCreated();

    DB::table('libreria.venta_cuotas')->insert([...$claim, 'venta_id' => 2]);

    $this->assertDatabaseCount('libreria.venta_cuotas', 2);
    $this->assertDatabaseHas('libreria.venta_cuotas', ['venta_id' => 1, 'reserva_activa' => null]);
    $this->assertDatabaseHas('libreria.venta_cuotas', ['venta_id' => 2, 'reserva_activa' => true]);
});

test('correction invalidations require an accepted replacement and preserve its UUID in the event', function () {
    $id = acceptedSaleForInvalidation($this);
    $input = invalidationInput();
    $input['reasonType'] = '1';
    $input['replacement'] = (string) Str::uuid();
    $this->postJson(route('libreria.ventas.invalidacion.store', $id), $input)->assertUnprocessable()->assertJsonValidationErrors('replacement');
    $sale = dteSalePayload();
    $sale['requestKey'] = (string) Str::uuid();
    $other = $this->postJson(route('libreria.ventas.store'), $sale)->assertCreated()->json();
    DB::table('libreria.venta_dtes')->where('id', $other['id'])->update(['transmision_estado' => 'ACEPTADO_PRUEBA', 'sello_recepcion' => str_repeat('A', 40)]);
    $input['replacement'] = $other['uuid'];

    $this->postJson(route('libreria.ventas.invalidacion.store', $id), $input)->assertCreated();

    $event = json_decode(DB::table('libreria.venta_invalidaciones')->value('documento'), true);
    expect($event['documento']['codigoGeneracionR'])->toBe($other['uuid']);
    expect($event['motivo']['tipoAnulacion'])->toBe(1);
    Http::assertNothingSent();
});

test('expired invoices and an environment changed after preparation cannot be transmitted', function () {
    $id = acceptedSaleForInvalidation($this);
    $this->travelTo(now('America/El_Salvador')->addMonthsNoOverflow(4));
    $this->postJson(route('libreria.ventas.invalidacion.store', $id), invalidationInput())->assertUnprocessable()->assertJsonValidationErrors('api');
    $this->travelTo(now('America/El_Salvador')->setDate(2026, 11, 11));
    prepareInvalidationEvent($this, $id);
    config(['services.factec.environment' => '01']);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertUnprocessable()->assertJsonValidationErrors('api');

    Http::assertNothingSent();
});

test('credit fiscal invalidations preserve the original event date and receiver NIT', function () {
    $id = acceptedSaleForInvalidation($this, '00', '03');
    $this->travelTo(now('America/El_Salvador')->addDay());

    $event = prepareInvalidationEvent($this, $id);
    $json = json_decode($event->documento, true);

    expect($json['identificacion']['version'])->toBe(3);
    expect($json['identificacion']['fecEmi'])->toBe('2026-11-11');
    expect($json['documento'])->toMatchArray(['tipoDte' => '03', 'tipoDocumento' => '36', 'numDocumento' => '06140000000000']);
    Http::assertNothingSent();
});

test('transmission preserves a prepared event date instead of moving it to the current day', function () {
    $id = acceptedSaleForInvalidation($this);
    $this->travelTo(now('America/El_Salvador')->setDate(2027, 2, 11));
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event);
    $this->travelTo(now('America/El_Salvador')->addDay());

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://factec.example.test/api/invalidacion/' && $request['json_data']['identificacion']['fecEmi'] === '2027-02-11');
    Http::assertSentCount(2);
});

test('service only sales can be annulled without creating inventory movements', function () {
    $id = acceptedSaleForInvalidation($this, '00', '01', 'S');
    $event = prepareInvalidationEvent($this, $id);
    fakeInvalidationAcceptance($event);
    DB::table('inv.inv_cat_tipo_mov')->where('tipo_mov', 'AJUSTE_POS')->delete();

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertOk()->assertJsonPath('applied', true);
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->assertJsonPath('status', 'ANULADO')->assertJsonPath('invalidationState', 'ACEPTADO_PRUEBA');

    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'estado' => 'ANULADO']);
    $this->assertDatabaseCount('inv.inv_documentos', 0);
    $this->assertDatabaseCount('inv.inv_documentos_det', 0);
    Http::assertSentCount(2);
});

test('a different event in the external store cannot be overwritten or transmitted', function () {
    $id = acceptedSaleForInvalidation($this);
    $event = prepareInvalidationEvent($this, $id);
    $other = json_decode($event->documento, true);
    $other['identificacion']['codigoGeneracion'] = (string) Str::uuid();
    $other = json_encode($other);
    DB::connection('dtesantacecilia')->table('public.dte_json')->update(['json_invalid' => $other]);

    $this->postJson(route('libreria.ventas.invalidacion.transmit', $id))->assertUnprocessable()->assertJsonValidationErrors('api');

    $this->assertDatabaseHas('public.dte_json', ['json_invalid' => $other, 'invalidado' => false], 'dtesantacecilia');
    $this->assertDatabaseHas('libreria.ventas', ['estado' => 'GENERADO']);
    Http::assertNothingSent();
});

test('the migration preserves one active reservation and allows historical references after release', function () {
    DB::statement("ATTACH DATABASE ':memory:' AS libreria");
    Schema::create('libreria.venta_cuotas', function (Blueprint $table) {
        $table->id();
        $table->string('saldo_id');
        $table->string('financiamiento');
        $table->integer('anio');
        $table->string('ambiente');
        $table->unique(['saldo_id', 'financiamiento', 'anio', 'ambiente'], 'venta_cuotas_saldo_financiamiento_anio_ambiente_unique');
    });
    $claim = ['saldo_id' => '101', 'financiamiento' => 'normal', 'anio' => 2025, 'ambiente' => '00'];
    DB::table('libreria.venta_cuotas')->insert($claim);
    $migration = require database_path('migrations/2026_11_12_211455_create_venta_invalidaciones_table.php');

    $migration->up();
    DB::table('libreria.venta_cuotas')->where('id', 1)->update(['reserva_activa' => null, 'liberada_at' => '2026-11-11 10:30:00']);
    DB::table('libreria.venta_cuotas')->insert($claim);
    expect(fn () => DB::table('libreria.venta_cuotas')->insert($claim))->toThrow(QueryException::class);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

    $this->assertDatabaseCount('libreria.venta_cuotas', 2);
    $this->assertDatabaseHas('libreria.venta_cuotas', ['id' => 1, 'reserva_activa' => null]);
    $this->assertDatabaseHas('libreria.venta_cuotas', ['id' => 2, 'reserva_activa' => true]);
});
