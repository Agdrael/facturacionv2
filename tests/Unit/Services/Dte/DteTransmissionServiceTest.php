<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

require_once __DIR__.'/../../../Fixtures/DteDatabase.php';
uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'services.factec.username' => 'test-user', 'services.factec.password' => 'test-password', 'services.factec.base_url' => 'https://factec.example.test', 'services.factec.environment' => '00', 'services.factec.transmission_enabled' => true]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $this->withoutVite();
    $this->travelTo(now('America/El_Salvador')->setDate(2026, 11, 11)->setTime(10, 30));
});

function createTransmittableDte(TestCase $test): int
{
    prepareDteGeneration($test);

    return $test->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->json('id');
}

test('a test DTE transmits its own metadata and is confirmed without changing platform balances', function () {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    prepareAcademicGeneration();
    $uuid = DB::table('libreria.venta_dtes')->where('id', $id)->value('codigo_generacion');
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['token' => 'fake-token']),
        'https://factec.example.test/api/hacienda/' => Http::response(['status' => 'OK', 'body' => ['respuestaHacienda' => ['estado' => 'PROCESADO', 'codigoGeneracion' => $uuid, 'selloRecibido' => 'TEST-SEAL', 'ambiente' => '00']]])]);

    $this->postJson(route('libreria.ventas.transmit', $id))->assertOk()->assertJsonPath('transmissionState', 'ACEPTADO_PRUEBA')->assertJsonPath('paymentsApplied', false);
    $this->postJson(route('libreria.ventas.transmit', $id))->assertOk();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://factec.example.test/api/hacienda/' && $request['ambiente'] === '00' && $request['version'] === 2 && $request['codigo_generacion'] === $uuid && $request['json_data']['identificacion']['ambiente'] === '00' && $request->hasHeader('Authorization', 'Bearer fake-token'));
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'saldo' => 50, 'pago' => 0], 'plataforma');
    $this->assertDatabaseCount('detalle_pagos', 0, 'plataforma');
    $this->assertDatabaseHas('libreria.ventas', ['estado' => 'GENERADO']);
});

test('rejected or uncertain replies never settle a sale and cannot trigger another POST', function (array $body, int $http, string $state) {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['token' => 'fake-token']), 'https://factec.example.test/api/hacienda/' => Http::response($body, $http)]);

    $this->postJson(route('libreria.ventas.transmit', $id))->assertOk()->assertJsonPath('transmissionState', $state)->assertJsonPath('paymentsApplied', false);
    $this->postJson(route('libreria.ventas.transmit', $id))->assertOk()->assertJsonPath('transmissionState', $state);
    Http::assertSentCount(2);
    $this->assertDatabaseHas('libreria.ventas', ['estado' => 'GENERADO']);
})->with([
    'rejection' => [['status' => 'ERROR', 'body' => ['estado' => 'RECHAZADO', 'descripcionMsg' => 'Dato inválido']], 200, 'RECHAZADO'],
    'no seal' => [['status' => 'OK', 'body' => ['estado' => 'PROCESADO']], 200, 'REVISION'],
    '500 with apparent seal' => [['status' => 'OK', 'body' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SEAL']], 500, 'REVISION'],
]);

test('a lost response persists its attempt marker in both databases and prevents another transmission', function () {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['token' => 'fake-token']), 'https://factec.example.test/api/hacienda/' => Http::failedConnection()]);
    $this->postJson(route('libreria.ventas.transmit', $id))->assertOk()->assertJsonPath('transmissionState', 'REVISION');
    $this->postJson(route('libreria.ventas.transmit', $id))->assertOk();
    Http::assertSentCount(2);
    expect(DB::connection('dtesantacecilia')->table('public.dte_json')->value('procesado'))->toBe(1);
});

test('a login failure leaves the DTE eligible for a safe first transmission', function () {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['detail' => 'Unauthorized'], 401)]);
    $this->postJson(route('libreria.ventas.transmit', $id))->assertUnprocessable()->assertJsonValidationErrors('api');
    Http::assertSentCount(1);
    $this->assertDatabaseHas('libreria.venta_dtes', ['id' => $id, 'transmision_estado' => 'PENDIENTE']);
    expect(DB::connection('dtesantacecilia')->table('public.dte_json')->value('procesado'))->toBe(0);
});

test('the configured test environment refuses an existing production document', function () {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    $document = json_decode(DB::table('libreria.venta_dtes')->where('id', $id)->value('documento'), true);
    $document['identificacion']['ambiente'] = '01';
    DB::table('libreria.venta_dtes')->where('id', $id)->update(['documento' => json_encode($document)]);
    $this->postJson(route('libreria.ventas.transmit', $id))->assertUnprocessable()->assertJsonValidationErrors('api');
    Http::assertNothingSent();
});

test('a late stored acceptance reconciles an interrupted request without transmitting again', function () {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    DB::table('libreria.venta_dtes')->where('id', $id)->update(['transmision_estado' => 'ENVIANDO']);
    $uuid = DB::table('libreria.venta_dtes')->where('id', $id)->value('codigo_generacion');
    DB::connection('dtesantacecilia')->table('public.dte_json')->update(['procesado' => true, 'error' => null, 'respuesta2' => json_encode(['status' => 'OK', 'body' => ['estado' => 'PROCESADO', 'selloRecibido' => 'LATE-SEAL', 'codigoGeneracion' => $uuid, 'ambiente' => '00']])]);
    $this->postJson(route('libreria.ventas.transmit', $id))->assertOk()->assertJsonPath('transmissionState', 'ACEPTADO_PRUEBA');
    Http::assertNothingSent();
});

test('only the owner can transmit an authenticated DTE', function () {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    $this->actingAs(User::factory()->make(['id' => 88]));
    $this->postJson(route('libreria.ventas.transmit', $id))->assertForbidden();
    Http::assertNothingSent();
});

test('a disabled connector never authenticates or claims a document', function () {
    Http::preventStrayRequests();
    $id = createTransmittableDte($this);
    config(['services.factec.transmission_enabled' => false]);
    $this->postJson(route('libreria.ventas.transmit', $id))->assertUnprocessable()->assertJsonValidationErrors('api');
    Http::assertNothingSent();
    $this->assertDatabaseHas('libreria.venta_dtes', ['id' => $id, 'transmision_estado' => 'PENDIENTE']);
});
