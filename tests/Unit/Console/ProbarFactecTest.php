<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

require_once __DIR__.'/../../Fixtures/DteDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'services.factec.username' => 'test-user',
        'services.factec.password' => 'secret-password',
        'services.factec.base_url' => 'https://factec.example.test',
        'services.factec.environment' => '00',
        'services.factec.transmission_enabled' => false,
    ]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    Http::preventStrayRequests();
    $this->withoutVite();
    $this->travelTo(now('America/El_Salvador')->setDate(2026, 11, 11)->setTime(10, 30));
});

test('the default command authenticates without a database or transmitting documents and hides secrets', function () {
    Http::fake(['https://factec.example.test/api/login/' => Http::response(['token' => 'secret-token'])]);

    $this->artisan('factec:probar')
        ->expectsOutputToContain('Autenticación correcta. No se transmitió ningún DTE.')
        ->expectsOutputToContain('transmisión de documentos continúa deshabilitada')
        ->doesntExpectOutputToContain('secret-token')
        ->doesntExpectOutputToContain('secret-password')
        ->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://factec.example.test/api/login/' && $request['username'] === 'test-user' && $request['password'] === 'secret-password');
});

test('the command refuses a productive configuration before making requests', function () {
    config(['services.factec.environment' => '01']);

    $this->artisan('factec:probar')->expectsOutputToContain('solo permite el ambiente 00')->assertFailed();

    Http::assertNothingSent();
});

test('invalid document references fail before accessing the database or API', function (string $reference) {
    $this->artisan('factec:probar', ['--dte' => $reference])->assertFailed();

    Http::assertNothingSent();
})->with(['', ' ', 'invalid', '-1', '9999999999999999999999999']);

test('missing credentials produce an actionable failure without sending a request', function () {
    config(['services.factec.password' => '']);

    $this->artisan('factec:probar')->expectsOutputToContain('Configura el usuario y contraseña')->assertFailed();

    Http::assertNothingSent();
});

test('authentication failures never print the provider response or a token', function (array $response, int $status) {
    Http::fake(['https://factec.example.test/api/login/' => Http::response($response, $status)]);

    $this->artisan('factec:probar')->expectsOutputToContain('FactEC no confirmó la autenticación')
        ->doesntExpectOutputToContain('provider-secret')->assertFailed();

    Http::assertSentCount(1);
})->with([
    'unauthorized' => [['detail' => 'provider-secret'], 401],
    'missing token' => [['detail' => 'provider-secret'], 200],
]);

test('a connection failure produces a friendly error', function () {
    Http::fake(['https://factec.example.test/api/login/' => Http::failedConnection()]);

    $this->artisan('factec:probar')->expectsOutputToContain('No se pudo conectar con FactEC')->assertFailed();
});

test('an unknown document is reported without authenticating', function () {
    prepareDteGeneration($this);

    $this->artisan('factec:probar', ['--dte' => '999'])->expectsOutputToContain('No se encontró ese DTE')->assertFailed();

    Http::assertNothingSent();
});

test('transmitting a registered document respects the disabled connector', function () {
    prepareDteGeneration($this);
    $id = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->json('id');

    $this->artisan('factec:probar', ['--dte' => (string) $id])->expectsOutputToContain('transmisión está deshabilitada')->assertFailed();

    Http::assertNothingSent();
    $this->assertDatabaseHas('libreria.venta_dtes', ['id' => $id, 'transmision_estado' => 'PENDIENTE']);
});

test('registered documents can be tested by ID UUID or control number without resending accepted documents', function (string $referenceColumn) {
    prepareDteGeneration($this);
    prepareAcademicGeneration();
    $id = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->json('id');
    $journal = DB::table('libreria.venta_dtes')->where('id', $id)->first();
    config(['services.factec.transmission_enabled' => true]);
    Http::fake([
        'https://factec.example.test/api/login/' => Http::response(['token' => 'secret-token']),
        'https://factec.example.test/api/hacienda/' => Http::response(['status' => 'OK', 'body' => [
            'estado' => 'PROCESADO', 'codigoGeneracion' => $journal->codigo_generacion, 'selloRecibido' => 'TEST-SEAL', 'ambiente' => '00',
        ]]),
    ]);

    $this->artisan('factec:probar', ['--dte' => (string) $journal->{$referenceColumn}])
        ->expectsOutputToContain('ACEPTADO_PRUEBA')->doesntExpectOutputToContain('secret-token')->assertSuccessful();
    config(['services.factec.transmission_enabled' => false]);
    $this->artisan('factec:probar', ['--dte' => (string) $id])->expectsOutputToContain('ACEPTADO_PRUEBA')->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://factec.example.test/api/hacienda/' && $request['ambiente'] === '00');
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'saldo' => 50, 'pago' => 0], 'plataforma');
    $this->assertDatabaseCount('detalle_pagos', 0, 'plataforma');
})->with(['id', 'codigo_generacion', 'numero_control']);

test('rejected and uncertain documents return failure and cannot be resent by running the command again', function (array $response, string $state) {
    prepareDteGeneration($this);
    $id = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->json('id');
    config(['services.factec.transmission_enabled' => true]);
    Http::fake([
        'https://factec.example.test/api/login/' => Http::response(['token' => 'secret-token']),
        'https://factec.example.test/api/hacienda/' => Http::response($response),
    ]);

    $this->artisan('factec:probar', ['--dte' => (string) $id])->expectsOutputToContain('Estado: '.$state)->assertFailed();
    $this->artisan('factec:probar', ['--dte' => (string) $id])->expectsOutputToContain('Estado: '.$state)->assertFailed();

    Http::assertSentCount(2);
})->with([
    'rejected' => [['status' => 'ERROR', 'body' => ['estado' => 'RECHAZADO', 'descripcionMsg' => 'Dato inválido']], 'RECHAZADO'],
    'uncertain' => [['status' => 'OK'], 'REVISION'],
]);

test('a production document cannot be transmitted even when the connector is enabled', function () {
    prepareDteGeneration($this);
    $id = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->json('id');
    $document = json_decode(DB::table('libreria.venta_dtes')->where('id', $id)->value('documento'), true);
    $document['identificacion']['ambiente'] = '01';
    DB::table('libreria.venta_dtes')->where('id', $id)->update(['documento' => json_encode($document)]);
    config(['services.factec.transmission_enabled' => true]);

    $this->artisan('factec:probar', ['--dte' => (string) $id])->expectsOutputToContain('DTE pertenece a otro ambiente')->assertFailed();

    Http::assertNothingSent();
});
