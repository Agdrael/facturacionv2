<?php

use App\Models\User;
use App\Services\Libreria\SaleTicketService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../../../../Fixtures/DteDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $this->withoutVite();
    $this->travelTo(now('America/El_Salvador')->setDate(2026, 11, 11)->setTime(10, 30));
});

test('guests cannot generate download or retry a DTE', function () {
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertUnauthorized();
    $this->postJson(route('libreria.ventas.retry', 1))->assertUnauthorized();
    $this->getJson(route('libreria.ventas.download', 1))->assertUnauthorized();
});

test('generated invoices provide a private printable PDF ticket with the original Hacienda consultation data', function () {
    prepareDteGeneration($this);
    $result = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated();
    $ticket = $this->get($result->json('ticketUrl'))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($ticket->getContent())->toStartWith('%PDF-');
    $row = DB::table('libreria.venta_dtes')->first();
    $document = json_decode($row->documento, true);
    $url = app(SaleTicketService::class)->consultationUrl($document);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query)->toBe(['ambiente' => $document['identificacion']['ambiente'], 'codGen' => $result->json('uuid'), 'fechaEmi' => '2026-11-11']);
    $this->actingAs(User::factory()->make(['id' => 8]));
    grantTestRoles(auth()->user(), ['libreria']);
    $this->get($result->json('ticketUrl'))->assertForbidden();
});

test('a generation writes one sale and separates consigned and own stock in one inventory document', function () {
    prepareDteGeneration($this);
    $result = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->assertJsonPath('saved', true)->assertJsonPath('status', 'GENERADO');
    $this->assertDatabaseHas('libreria.ventas', ['total' => 56.5, 'estado' => 'GENERADO', 'caja' => 'P004']);
    $this->assertDatabaseHas('libreria.venta_detalles', ['valor_unitario' => 10, 'cantidad' => 5]);
    $this->assertDatabaseCount('inv.inv_documentos', 1);
    $this->assertDatabaseHas('inv.inv_documentos_det', ['propiedad' => 'CONSIGNADO', 'cantidad' => 3, 'tipo_mov' => 'VENTA']);
    $this->assertDatabaseHas('inv.inv_documentos_det', ['propiedad' => 'PROPIO', 'cantidad' => 2, 'tipo_mov' => 'VENTA']);
    expect(DB::connection('dtesantacecilia')->table('public.dte_json')->count())->toBe(1);
    $document = json_decode(DB::connection('dtesantacecilia')->table('public.dte_json')->value('resultado'), true);
    expect($document['identificacion']['version'])->toBe(2)
        ->and($document['resumen']['pagos'][0]['montoPago'])->toBe(56.5)
        ->and($document)->not->toHaveKey('extension');
    $this->get($result->json('downloadUrl'))->assertOk()->assertHeader('content-type', 'application/json; charset=utf-8');
    $this->getJson(route('libreria.ventas.history'))->assertOk()->assertJsonPath('data.0.client', 'Cliente de la factura');
});

test('repeating a successful request returns the same DTE without another sale or inventory output', function () {
    prepareDteGeneration($this);
    $first = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated();
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->assertJsonPath('uuid', $first->json('uuid'));
    $this->assertDatabaseCount('libreria.ventas', 1);
    $this->assertDatabaseCount('inv.inv_documentos_det', 2);
    expect(DB::connection('dtesantacecilia')->table('public.dte_json')->count())->toBe(1);
});

test('a changed payload cannot reuse a saved generation key', function () {
    prepareDteGeneration($this);
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated();
    $input = dteSalePayload();
    $input['client']['name'] = 'Otro receptor';
    $this->postJson(route('libreria.ventas.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('requestKey');
    $this->assertDatabaseCount('libreria.ventas', 1);
});

test('a remote failure leaves a recoverable copy and retry does not sell the products again', function () {
    prepareDteGeneration($this);
    $remote = DB::connection('dtesantacecilia');
    $remote->statement("CREATE TRIGGER public.fail_remote BEFORE INSERT ON dte_json BEGIN SELECT RAISE(ABORT,'Unavailable'); END");
    $result = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertAccepted()->assertJsonPath('saved', false);
    $this->assertDatabaseCount('libreria.venta_dtes', 1);
    $remote->statement('DROP TRIGGER public.fail_remote');
    $this->postJson($result->json('retryUrl'))->assertCreated()->assertJsonPath('saved', true);
    $this->postJson($result->json('retryUrl'))->assertCreated();
    $this->assertDatabaseCount('libreria.ventas', 1);
    $this->assertDatabaseCount('inv.inv_documentos_det', 2);
    expect($remote->table('public.dte_json')->count())->toBe(1);
});

test('a local inventory failure rolls back the sale details and the DTE before touching the other connection', function () {
    prepareDteGeneration($this);
    DB::statement("CREATE TRIGGER inv.fail_detail BEFORE INSERT ON inv_documentos_det BEGIN SELECT RAISE(ABORT,'Unavailable'); END");
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertInternalServerError();
    $this->assertDatabaseCount('libreria.ventas', 0);
    $this->assertDatabaseCount('libreria.venta_detalles', 0);
    $this->assertDatabaseCount('libreria.venta_dtes', 0);
    expect(DB::connection('dtesantacecilia')->table('public.dte_json')->count())->toBe(0);
});

test('the server rejects insufficient inventory invalid totals and unsupported discounts before saving', function (string $field, mixed $value, string $error) {
    prepareDteGeneration($this);
    $input = dteSalePayload();
    data_set($input, $field, $value);
    if ($error === 'discount') {
        DB::table('public.productos')->update(['descuento' => false]);
        $error = 'items.0';
    }
    $this->postJson(route('libreria.ventas.store'), $input)->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->assertDatabaseCount('libreria.ventas', 0);
})->with(['stock' => ['items.0.quantity', '9', 'items'], 'total' => ['total', '1', 'total'], 'discount' => ['items.0.discount', 5, 'discount'], 'payment' => ['payments.0.amount', '1', 'payments'], 'noncash change' => ['payments.0.method', '02', 'payments'], 'credit' => ['operation', '2', 'operation']]);

test('preparation recalculates fixed prices without creating a sale or consuming a control number', function () {
    prepareDteGeneration($this);
    $this->postJson(route('libreria.ventas.quote'), dteSalePayload())->assertOk()->assertJsonPath('total', '56.50');
    $this->assertDatabaseCount('libreria.ventas', 0);
    $this->assertDatabaseCount('public.historial_dte', 0);
});

test('credit invoices require a NIT fiscal registration economic activity and complete geography', function () {
    prepareDteGeneration($this);
    $input = dteSalePayload();
    $input['document']['type'] = '03';
    $this->postJson(route('libreria.ventas.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('client');
    $this->assertDatabaseCount('libreria.ventas', 0);
});

test('credit fiscal rejects the default client despite edited billing details', function (string $route, ?int $clientId) {
    prepareDteGeneration($this);
    $input = dteSalePayload();
    $input['document']['type'] = '03';
    $input['client'] = ['id' => $clientId, 'name' => 'Empresa con datos editados', 'documentType' => '36', 'document' => '0614-000000-000-0',
        'nrc' => '1234567', 'activity' => '12345', 'department' => '05', 'municipality' => '01', 'district' => '96', 'address' => 'Dirección del receptor'];

    $this->postJson(route($route), $input)->assertUnprocessable()->assertJsonValidationErrors('client.id');

    $this->assertDatabaseCount('libreria.ventas', 0);
    $this->assertDatabaseCount('libreria.venta_dtes', 0);
    $this->assertDatabaseCount('inv.inv_documentos', 0);
    $this->assertDatabaseCount('public.historial_dte', 0);
})->with([
    'quote with default ID' => ['libreria.ventas.quote', 1],
    'generation with default ID' => ['libreria.ventas.store', 1],
    'generation with fallback client' => ['libreria.ventas.store', null],
]);

test('another operator cannot download or retry a saved DTE', function () {
    prepareDteGeneration($this);
    $result = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated();
    $this->actingAs(User::factory()->make(['id' => 8]));
    grantTestRoles(auth()->user(), ['libreria']);
    $this->get($result->json('downloadUrl'))->assertForbidden();
    $this->postJson($result->json('retryUrl'))->assertForbidden();
});

test('credit fiscal generation uses version four with net prices and the IVA summary', function () {
    prepareDteGeneration($this);
    DB::table('libreria.clientes')->insert(['id' => 2, 'nombre' => 'Empresa receptora', 'credito' => false]);
    $input = dteSalePayload();
    $input['document']['type'] = '03';
    $input['client'] = [...$input['client'], 'id' => 2, 'name' => 'Empresa receptora', 'documentType' => '36', 'document' => '0614-000000-000-0', 'nrc' => '1234567', 'activity' => '12345', 'department' => '05', 'municipality' => '01', 'district' => '96', 'address' => 'Dirección de prueba'];
    $this->postJson(route('libreria.ventas.store'), $input)->assertCreated();
    $this->assertDatabaseHas('libreria.ventas', ['cliente_id' => 2, 'tipo_documento' => '03']);
    $json = json_decode(DB::connection('dtesantacecilia')->table('public.dte_json')->value('resultado'), true);
    expect($json['identificacion']['version'])->toBe(4)->and($json['cuerpoDocumento'][0]['precioUni'])->toBe(10.0)
        ->and($json['resumen']['totalGravada'])->toBe(50.0)->and($json['resumen']['tributos'][0]['valor'])->toBe(6.5);
});

test('variable prices and sequential discounts are recalculated on the server', function () {
    prepareDteGeneration($this);
    DB::table('public.productos')->update(['tipo_prec' => 'V']);
    $input = dteSalePayload();
    $input['items'][0]['netPrice'] = '20';
    $input['items'][0]['discount'] = 10;
    $input['document']['discount'] = 5;
    $this->postJson(route('libreria.ventas.quote'), $input)->assertOk()->assertJsonPath('total', '96.61');
    $input['total'] = '96.61';
    $input['payments'][0]['amount'] = '100';
    $this->postJson(route('libreria.ventas.store'), $input)->assertCreated();
    $this->assertDatabaseHas('libreria.venta_detalles', ['valor_unitario' => 20, 'valor_descuento' => 14.5]);
    $this->assertDatabaseHas('public.productos', ['p_venta' => 10]);
});

test('different students and funding types share one DTE using authoritative balances and source references', function () {
    prepareDteGeneration($this);
    prepareAcademicGeneration();
    $input = dteSalePayload();
    $input['items'] = [];
    $input['client']['id'] = null;
    foreach ([['101', 'normal', 'clientes_varios'], ['102', 'beca', 'beca']] as [$student,$funding,$responsible]) {
        $input['items'][] = ['productId' => 2, 'quantity' => '1', 'netPrice' => '0.01', 'tuition' => ['studentId' => $student, 'balanceId' => $student, 'fundingType' => $funding, 'responsibleType' => $responsible]];
    }
    $input['total'] = '75.00';
    $input['payments'][0]['amount'] = '75';
    $this->postJson(route('libreria.ventas.store'), $input)->assertCreated();
    $this->assertDatabaseHas('libreria.venta_detalles', ['carnet_estudiante' => '101', 'valor_unitario' => 50]);
    $this->assertDatabaseHas('libreria.venta_detalles', ['carnet_estudiante' => '102', 'valor_unitario' => 25]);
    $this->assertDatabaseCount('libreria.venta_cuotas', 2);
    $this->assertDatabaseCount('inv.inv_documentos', 0);
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 101, 'pago' => 0, 'saldo' => 50], 'plataforma');
    $this->assertDatabaseHas('saldo_pagos', ['id_saldo' => 102, 'pago_beca' => 0, 'saldo_beca' => 25], 'plataforma');
    $input['requestKey'] = '91d32456-e927-46f1-ad24-0d9c9fa4871f';
    $this->postJson(route('libreria.ventas.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('items.0');
    $this->assertDatabaseCount('libreria.ventas', 1);
});

test('academic generation remains blocked while its platform payment timing is undecided', function () {
    prepareDteGeneration($this);
    config(['libreria.academic_payment_timing' => null]);
    $input = dteSalePayload();
    $input['items'][0]['tuition'] = ['studentId' => '101', 'balanceId' => '101', 'fundingType' => 'normal', 'responsibleType' => 'clientes_varios'];

    $this->postJson(route('libreria.ventas.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('items');

    $this->assertDatabaseCount('libreria.ventas', 0);
    $this->assertDatabaseCount('libreria.venta_dtes', 0);
    $this->assertDatabaseCount('inv.inv_documentos', 0);
});
