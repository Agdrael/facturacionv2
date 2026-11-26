<?php

use App\Models\Libreria\Receipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\CashSummaryService;
use App\Services\Libreria\CashWorkbookService;
use App\Services\Libreria\ReceiptCreditService;
use App\Services\Libreria\ReceiptMoney;
use App\Services\Libreria\ReceiptSourceService;
use App\Services\Libreria\ReceiptTicketService;
use Database\Seeders\ReceiptControlSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

require_once __DIR__.'/../../../../Fixtures/ReceiptDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    $this->withoutVite();
});

test('retreats and sacraments require a real student even when issued by a direct request', function (string $name) {
    prepareReceipts($this);
    DB::table('public.catalogo_recibos')->insert(['id' => 4, 'nombre_recibo' => $name, 'cuenta_abono' => '5101', 'precio' => 10]);
    $concept = collect(app(ReceiptSourceService::class)->concepts())->firstWhere('id', 4);
    expect($concept['requiresStudent'])->toBeTrue();
    $input = receiptPayload();
    $input['conceptId'] = 4;

    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('receipts');
    $input['studentId'] = '999999';
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable()->assertJsonValidationErrors('receipts');

    $this->assertDatabaseCount('libreria.receipts', 0);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['RETIROS UN DIA', 'RETIRO DE PROMOCION', 'CONFIRMA', 'CUOTAS 1° COMUNION COLECTIVAS', 'Primera Comunión']);

test('student references remain historical searchable metadata without changing the payer ticket or academic balances', function () {
    $user = prepareReceipts($this);
    DB::table('public.catalogo_recibos')->insert(['id' => 4, 'nombre_recibo' => 'RETIRO DE CONFIRMA', 'cuenta_abono' => '5101', 'precio' => 10]);
    $platform = DB::connection('plataforma');
    $platform->table('public.saldo_pagos')->insert(['id_saldo' => 9, 'student_id' => '501', 'syear' => 2025, 'mes' => 9,
        'cuota' => 100, 'pago' => 10, 'saldo' => 90, 'cuota_beca' => 20, 'pago_beca' => 5, 'saldo_beca' => 15,
        'cuota_bienhechor' => 30, 'pago_bienhechor' => 10, 'saldo_bienhechor' => 20]);
    $balances = $platform->table('public.saldo_pagos')->get()->all();
    $input = receiptPayload();
    $input['conceptId'] = 4;
    $input['studentId'] = '501';

    $response = $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $receipt = Receipt::query()->findOrFail($response->json('id'));
    expect($receipt->recipient_type)->toBe('manual');
    expect($receipt->recipient_name)->toBe('Destinatario de prueba');
    $line = DB::table('libreria.receipt_lines')->where('receipt_id', $receipt->id)->sole();
    expect($line->kind)->toBe('normal')->and($line->obligation_id)->toBeNull();
    expect(json_decode($line->data, true))->toMatchArray(['account' => '5101', 'history_student' => ['id' => '501', 'name' => 'Alumno Uno']]);
    expect($platform->table('public.saldo_pagos')->get()->all())->toEqual($balances);
    expect($platform->table('public.cxc')->count())->toBe(0);
    expect($platform->table('public.detalle_pagos')->count())->toBe(0);
    $this->assertDatabaseCount('libreria.receipt_obligations', 0);
    expect(DB::table('libreria.receipt_integrations')->where('target', 'platform')->count())->toBe(0);
    $this->assertDatabaseHas('public.recibos', ['numero_carnet' => '501', 'nombre_cliente' => 'Destinatario de prueba',
        'cuenta_abono' => '5101', 'precio' => 10], 'receipt_legacy');
    $platform->table('public.students')->where('student_id', 501)->update(['first_name' => 'Nombre cambiado']);
    foreach (['501', 'ALUMNO UNO', 'Destinatario'] as $search) {
        $this->getJson(route('libreria.recibos.history', ['q' => $search]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $receipt->id)
            ->assertJsonPath('data.0.student', ['id' => '501', 'name' => 'Alumno Uno']);
    }
    $document = app(ReceiptTicketService::class)->document($receipt);
    $html = view('libreria.recibos.ticket', ['receipt' => $document, 'emitter' => (object) $document['issuer']])->render();
    expect($html)->toContain('Destinatario de prueba')->not->toContain('Alumno Uno');
    $other = User::factory()->create();
    DB::table('libreria.receipts')->where('id', $receipt->id)->update(['user_id' => $other->id]);
    $user->roles()->sync(Role::query()->where('code', 'libreria')->pluck('id'));
    $this->getJson(route('libreria.recibos.history', ['q' => '501']))->assertOk()->assertJsonCount(0, 'data');
    Http::assertNothingSent();
});

test('one operation issues one private receipt with mixed payments and retries without duplicate collections', function () {
    prepareReceipts($this);
    $payload = receiptPayload();
    $payload['payments'] = [['method' => '01', 'amount' => '8.00'], ['method' => '02', 'amount' => '4.00', 'reference' => 'A123']];
    $result = $this->postJson(route('libreria.recibos.store'), $payload)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $this->postJson(route('libreria.recibos.store'), $payload)->assertCreated()->assertJsonPath('id', $result->json('id'));
    $this->assertDatabaseCount('libreria.receipts', 1);
    $this->assertDatabaseCount('libreria.receipt_lines', 1);
    $this->assertDatabaseHas('libreria.receipts', ['recipient_name' => 'Destinatario de prueba', 'change' => '2.00']);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->sum('precio'))->toEqual(10);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->orderBy('id')->pluck('precio')->all())->toEqual([6, 4]);
    $this->getJson(route('libreria.recibos.history', ['legacy' => true]))->assertOk()->assertJsonCount(0, 'data');
    $this->get($result->json('ticketUrl'))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('libreria.recibos.index'))->assertOk()->assertSee('Recibos')->assertSee('Tipo del catálogo');
    $this->postJson('/libreria/recibos/lotes', $payload)->assertNotFound();
    $invalid = receiptPayload();
    $invalid['recipients'] = [['name' => 'Otro destinatario']];
    $this->postJson(route('libreria.recibos.store'), $invalid)->assertUnprocessable();
    Http::assertNothingSent();
});

test('changed request contents and insufficient payment cannot duplicate or partially create a receipt', function () {
    prepareReceipts($this);
    $input = receiptPayload();
    $this->postJson(route('libreria.recibos.store'), $input)->assertCreated();
    $input['amount'] = '9.00';
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $input['requestKey'] = (string) Str::uuid();
    $input['payments'][0]['amount'] = '1.00';
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $this->assertDatabaseCount('libreria.receipts', 1);
    $this->assertDatabaseCount('libreria.receipt_batches', 1);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->count())->toBe(1);
});

test('a billed CXC with no revisions accepts partial receipts and never resets a zero balance', function (?string $opening) {
    prepareReceipts($this);
    $debt = receiptAcademicDebt($opening);
    $key = receiptLinkAcademic($debt);

    $result = $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key))->assertCreated()->assertJsonPath('status', 'EMITIDO');

    $this->assertDatabaseHas('public.cxc', ['id' => $debt['id'], 'parciales' => 30, 'recibo' => false], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['id_saldo' => 2025, 'saldo' => 30, 'pago' => 20], 'plataforma');
    expect(DB::connection('plataforma')->table('public.revisiones')->count())->toBe(0);
    $last = $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key, '30.00'))->assertCreated();
    $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key, '1.00'))->assertUnprocessable();
    $this->assertDatabaseHas('public.cxc', ['id' => $debt['id'], 'monto' => 50, 'parciales' => 0, 'recibo' => true], 'plataforma');
    $this->assertDatabaseCount('libreria.receipts', 2);
    $this->postJson($last->json('annulUrl'), ['reason' => 'Corregir el último pago completo'])->assertOk()->assertJsonPath('status', 'ANULADO');
    $this->assertDatabaseHas('public.cxc', ['id' => $debt['id'], 'parciales' => 30, 'recibo' => false], 'plataforma');
})->with(['null initializes once' => [null], 'explicit outstanding' => ['50.00']]);

test('received payments in revisions cannot become new debt and zero or mismatched CXC is blocked', function () {
    prepareReceipts($this);
    $debt = receiptAcademicDebt();
    DB::connection('plataforma')->table('public.revisiones')->insert(['student_id' => 501, 'item_pago' => 2, 'syear' => 2025,
        'monto' => 50, 'estado' => 'Resumen', 'facturado' => true, 'numero_factura' => $debt['uuid']]);

    $input = receiptAcademicPayload('academic:'.$debt['id'].':normal');
    $input['invoice'] = ['origin' => 'legacy', 'uuid' => $debt['uuid'], 'confirmed' => true];
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();

    $this->assertDatabaseCount('libreria.receipts', 0);
    $this->assertDatabaseCount('libreria.receipt_obligations', 0);
    $this->assertDatabaseHas('public.cxc', ['id' => $debt['id'], 'parciales' => null], 'plataforma');
});

test('academic receipt application is isolated by school year', function () {
    prepareReceipts($this);
    $old = receiptAcademicDebt('50.00', 2024);
    $new = receiptAcademicDebt('50.00', 2025);
    $key = receiptLinkAcademic($new);

    $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key))->assertCreated()->assertJsonPath('status', 'EMITIDO');

    $this->assertDatabaseHas('public.cxc', ['id' => $old['id'], 'parciales' => 50], 'plataforma');
    $this->assertDatabaseHas('public.cxc', ['id' => $new['id'], 'parciales' => 30], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['id_saldo' => 2024, 'saldo' => 50], 'plataforma');
});

test('an external commit survives a lost local acknowledgement and retry never applies the academic payment twice', function () {
    prepareReceipts($this);
    $key = receiptLinkAcademic(receiptAcademicDebt());
    DB::statement("CREATE TRIGGER libreria.fail_ack BEFORE UPDATE ON receipt_integrations WHEN NEW.target = 'platform' AND NEW.status = 'APLICADO' BEGIN SELECT RAISE(ABORT,'Lost acknowledgement'); END");

    $result = $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key))->assertCreated()->assertJsonPath('status', 'PENDIENTE_APLICACION');
    DB::statement('DROP TRIGGER libreria.fail_ack');
    $this->postJson($result->json('retryUrl'))->assertOk()->assertJsonPath('status', 'EMITIDO');

    $this->assertDatabaseHas('public.cxc', ['parciales' => 30], 'plataforma');
    expect(DB::connection('plataforma')->table('public.detalle_pagos')->count())->toBe(1);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->count())->toBe(1);
    $this->assertDatabaseCount('libreria.receipts', 1);
});

test('annulment reverses a confirmed partial receipt once and restores its available credit', function () {
    prepareReceipts($this);
    $key = receiptLinkAcademic(receiptAcademicDebt());
    $result = $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key))->assertCreated();

    $this->postJson($result->json('annulUrl'), ['reason' => 'Error en el destinatario'])->assertOk()->assertJsonPath('status', 'ANULADO');
    $this->postJson($result->json('annulUrl'), ['reason' => 'Error en el destinatario'])->assertOk()->assertJsonPath('status', 'ANULADO');

    $this->assertDatabaseHas('public.cxc', ['parciales' => 50, 'recibo' => false], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['saldo' => 50, 'pago' => 0], 'plataforma');
    $this->assertDatabaseHas('public.detalle_pagos', ['estado' => 'Anulado', 'monto' => 20], 'plataforma');
    $this->assertDatabaseHas('libreria.receipt_obligations', ['source_key' => $key, 'remaining' => 50]);
    expect(DB::connection('receipt_legacy')->table('public.recibos_anulados')->count())->toBe(1);
});

test('advances and partial photography are separate receipts and never force balances to zero', function () {
    prepareReceipts($this);
    $platform = DB::connection('plataforma');
    $platform->table('public.saldo_pagos')->insert(['id_saldo' => 20, 'student_id' => '501', 'mes' => 20, 'syear' => 2025, 'pago' => 0, 'saldo' => 11]);
    $advance = receiptPayload([['type' => 'cliente', 'id' => '1', 'lines' => [['conceptId' => 3, 'amount' => '15.00']], 'payments' => [['method' => '01', 'amount' => '15.00']]]]);
    $photo = receiptPayload([['type' => 'alumno', 'id' => '501', 'lines' => [['conceptId' => 2, 'amount' => '5.00', 'year' => 2025, 'funding' => 'normal']], 'payments' => [['method' => '01', 'amount' => '5.00']]]]);
    $this->postJson(route('libreria.recibos.store'), $advance)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $result = $this->postJson(route('libreria.recibos.store'), $photo)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $this->assertDatabaseHas('public.saldo_clientes', ['id_cliente' => 8, 'saldo' => -15], 'receipt_legacy');
    $this->assertDatabaseHas('public.saldo_pagos', ['id_saldo' => 20, 'saldo' => 6, 'pago' => 5], 'plataforma');
    $this->postJson($result->json('annulUrl'), ['reason' => 'Anular fotografía incorrecta'])->assertOk()->assertJsonPath('status', 'ANULADO');
    $this->assertDatabaseHas('public.saldo_pagos', ['id_saldo' => 20, 'saldo' => 11, 'pago' => 0], 'plataforma');
});

test('receipt collections enter the frozen cash snapshot separately from sales and later annulment is an incident', function () {
    $user = prepareReceipts($this, 'P001');
    $this->postJson(route('libreria.recibos.store'), receiptPayload())->assertUnprocessable();
    $session = app(CashRegisterService::class)->open($user);
    $payload = receiptPayload();
    $payload['payments'] = [['method' => '01', 'amount' => '8.00'], ['method' => '02', 'amount' => '4.00']];
    $result = $this->postJson(route('libreria.recibos.store'), $payload)->assertCreated();

    $record = app(CashRegisterService::class)->close($user, $session->id);

    expect($record->snapshot['total'])->toBe('0.00')->and($record->snapshot['receiptTotal'])->toBe('10.00')
        ->and($record->snapshot['methods']['01'])->toBe('6.00')->and($record->snapshot['collections'])->toBe('10.00');
    $reports = app(CashSummaryService::class);
    $summary = $reports->summary($record, 'recibos');
    expect($summary)->toMatchArray(['total' => '10.00', 'collected' => '10.00', 'count' => 1,
        'methods' => [['code' => '01', 'name' => 'Efectivo', 'amount' => '6.00'], ['code' => '02', 'name' => 'Tarjeta débito', 'amount' => '4.00']]]);
    expect($reports->summary($record, 'ventas'))->toMatchArray(['count' => 0, 'total' => '0.00', 'methods' => []]);
    $this->get(route('libreria.arqueos.summary', [$record->id, 'recibos']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $book = app(CashWorkbookService::class)->build($record);
    expect($book->getSheetByName('Arqueo')->getCell('F14')->getValue())->toEqual(10);
    $this->postJson($result->json('annulUrl'), ['reason' => 'Anulación posterior al cierre'])->assertOk();
    expect($record->fresh()->snapshot)->toBe($record->snapshot);
    expect($reports->summary($record->fresh(), 'recibos'))->toBe($summary);
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk()->assertSee($result->json('number'));
});

test('receipt ownership and supervisor membership protect direct downloads and annulments', function () {
    prepareReceipts($this);
    $result = $this->postJson(route('libreria.recibos.store'), receiptPayload())->assertCreated();
    $lib = User::factory()->create(['email' => 'otro@example.test']);
    DB::connection('dtesantacecilia')->table('public.empleados')->insert(['empleado_id' => 'E8', 'codigo' => 'EMP8', 'correo' => $lib->email]);
    grantTestRoles($lib, ['libreria']);
    $lib->setAttribute('puntoventa', 'P004');
    $this->actingAs($lib);

    $this->get($result->json('ticketUrl'))->assertForbidden();
    $this->postJson($result->json('annulUrl'), ['reason' => 'Intento sin autorización'])->assertForbidden();
    $this->postJson('/libreria/recibos/vincular', ['cxcId' => 1])->assertNotFound();
    grantTestRoles($lib, ['contador']);
    $this->get($result->json('ticketUrl'))->assertOk();
    $this->postJson($result->json('annulUrl'), ['reason' => 'Revisión de Contaduría'])->assertOk()->assertJsonPath('status', 'ANULADO');
    $this->assertDatabaseHas('libreria.receipts', ['id' => $result->json('id'), 'annulled_by' => $lib->id, 'annulled_employee_code' => 'EMP8']);
});

test('receipt permission synchronization preserves a later customized revocation', function () {
    prepareReceipts($this);
    $role = Role::query()->where('code', 'libreria')->sole();
    $permission = Permission::query()->where('code', 'libreria.recibos.crear')->sole();
    $role->permissions()->detach($permission->id);

    $this->seed(ReceiptControlSeeder::class);

    expect($role->permissions()->where('code', 'libreria.recibos.crear')->exists())->toBeFalse();
});

test('billed academic debt cannot be invoiced again or collected as a free concept', function () {
    prepareReceipts($this);
    receiptAcademicDebt();
    expect(fn () => app(ReceiptCreditService::class)->assertAcademicNotBilled('501', 2025, 2, 'normal'))->toThrow(ValidationException::class);
    DB::table('public.catalogo_recibos')->insert(['id' => 4, 'nombre_recibo' => 'ABONAR CXC', 'cuenta_abono' => '00', 'precio' => 0]);
    $input = receiptPayload();
    $input['conceptId'] = 4;
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $this->assertDatabaseCount('libreria.receipts', 0);
});

test('a changed external balance after collection requires review and never silently applies the reserved payment', function () {
    prepareReceipts($this);
    $key = receiptLinkAcademic(receiptAcademicDebt());
    $platform = DB::connection('plataforma');
    $platform->statement("CREATE TRIGGER public.unavailable BEFORE INSERT ON detalle_pagos BEGIN SELECT RAISE(ABORT,'Unavailable'); END");
    $result = $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key))->assertCreated()->assertJsonPath('status', 'PENDIENTE_APLICACION');
    $platform->statement('DROP TRIGGER public.unavailable');
    $platform->table('public.cxc')->update(['parciales' => 40]);
    $platform->table('public.saldo_pagos')->update(['saldo' => 40, 'pago' => 10]);

    $this->postJson($result->json('retryUrl'))->assertOk()->assertJsonPath('status', 'REVISION');

    $this->assertDatabaseCount('libreria.receipts', 1);
    $this->assertDatabaseHas('public.cxc', ['parciales' => 40], 'plataforma');
    expect($platform->table('public.detalle_pagos')->count())->toBe(0);
});

test('proportional initial payments preserve cents and deterministic ties', function () {
    expect(ReceiptMoney::distribute(1, [10 => 1, 20 => 1, 30 => 1]))->toBe([10 => 1, 20 => 0, 30 => 0]);
    expect(ReceiptMoney::distribute(2001, [10 => 5000, 20 => 2500]))->toBe([10 => 1334, 20 => 667]);
});

test('legacy customer debt receipts update the original cargo and ledger without creating another sale', function () {
    prepareReceipts($this);
    $debt = receiptAcademicDebt('40.00');
    DB::connection('receipt_legacy')->table('public.saldo_cargos')->insert(['id' => 1, 'id_cliente' => 8, 'saldo' => 50, 'saldo_pendiente' => 40, 'uuid_venta' => $debt['uuid'], 'anulado' => false]);
    $input = receiptPayload([['type' => 'legacy_cliente', 'id' => '8', 'lines' => [['obligationKey' => 'legacy:1', 'amount' => '10.00']], 'payments' => [['method' => '01', 'amount' => '10.00']]]]);

    $result = $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');

    $this->assertDatabaseHas('public.saldo_cargos', ['id' => 1, 'saldo_pendiente' => 30], 'receipt_legacy');
    $this->assertDatabaseHas('public.saldo_clientes', ['id_cliente' => 8, 'saldo' => 30], 'receipt_legacy');
    $this->assertDatabaseCount('libreria.ventas', 0);
    $this->postJson($result->json('annulUrl'), ['reason' => 'Cargo seleccionado incorrecto'])->assertOk()->assertJsonPath('status', 'ANULADO');
    $this->assertDatabaseHas('public.saldo_cargos', ['id' => 1, 'saldo_pendiente' => 40], 'receipt_legacy');
    $this->assertDatabaseHas('public.saldo_clientes', ['id_cliente' => 8, 'saldo' => 40], 'receipt_legacy');
});

test('annulling an unapplied academic effect cancels it and releases the reservation after reversing the compatible receipt', function () {
    prepareReceipts($this);
    $key = receiptLinkAcademic(receiptAcademicDebt());
    DB::connection('plataforma')->statement("CREATE TRIGGER public.unavailable BEFORE INSERT ON detalle_pagos BEGIN SELECT RAISE(ABORT,'Unavailable'); END");
    $result = $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($key))->assertCreated()->assertJsonPath('status', 'PENDIENTE_APLICACION');

    $this->postJson($result->json('annulUrl'), ['reason' => 'Cancelar cobro pendiente'])->assertOk()->assertJsonPath('status', 'ANULADO');

    $this->assertDatabaseHas('public.cxc', ['parciales' => null], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['saldo' => 50, 'pago' => 0], 'plataforma');
    expect(DB::connection('plataforma')->table('public.detalle_pagos')->count())->toBe(0);
    $this->assertDatabaseHas('libreria.receipt_obligations', ['source_key' => $key, 'remaining' => 50]);
});

test('accepted native credit registers its debt and initial payment once and blocks invalidation while receipts remain active', function () {
    prepareReceipts($this);
    $payload = dteSalePayload();
    $payload['document']['date'] = now('America/El_Salvador')->toDateString();
    $payload['operation'] = '2';
    $payload['payments'][0]['amount'] = '20.00';
    $result = $this->postJson(route('libreria.ventas.store'), $payload)->assertCreated();
    DB::table('libreria.venta_dtes')->where('id', $result->json('id'))->update(['transmision_estado' => 'ACEPTADO', 'sello_recepcion' => 'SEAL']);
    $detail = DB::table('libreria.venta_detalles')->first();
    DB::table('libreria.venta_detalles')->where('id', $detail->id)->update(['valor_final' => '30.00']);
    $second = (array) $detail;
    unset($second['id']);
    $second['valor_final'] = '26.50';
    DB::table('libreria.venta_detalles')->insert($second);

    $this->postJson(route('libreria.recibos.credits.register', $result->json('id')))->assertOk();
    $this->postJson(route('libreria.recibos.credits.register', $result->json('id')))->assertOk();

    expect(DB::table('libreria.receipt_obligations')->sum('remaining'))->toEqual(36.50);
    $this->assertDatabaseCount('libreria.receipt_obligations', 2);
    $this->assertDatabaseHas('public.saldo_cargos', ['saldo' => 56.50, 'saldo_pendiente' => 36.50], 'receipt_legacy');
    $this->assertDatabaseHas('public.saldo_clientes', ['id_cliente' => 8, 'saldo' => 36.50], 'receipt_legacy');
    expect(DB::connection('plataforma')->table('public.revisiones')->count())->toBe(0);
    $listed = $this->getJson(route('libreria.recibos.client_cxc', ['q' => 'Responsable']))->assertOk()->assertJsonCount(1, 'data');
    $key = $listed->json('data.0.key');
    $receipt = $this->postJson(route('libreria.recibos.store'), receiptPayload([['type' => 'cliente', 'id' => '1',
        'lines' => [['obligationKey' => $key, 'amount' => '36.50']], 'payments' => [['method' => '01', 'amount' => '36.50']]]]))->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $this->assertDatabaseHas('public.saldo_cargos', ['saldo_pendiente' => 0], 'receipt_legacy');
    expect(fn () => app(ReceiptCreditService::class)->assertNoReceipts($result->json('uuid')))->toThrow(ValidationException::class);
    $this->postJson($receipt->json('annulUrl'), ['reason' => 'Revertir antes de invalidar'])->assertOk()->assertJsonPath('status', 'ANULADO');
    $journal = DB::table('libreria.venta_dtes')->where('id', $result->json('id'))->first();
    app(ReceiptCreditService::class)->reverse($journal);
    app(ReceiptCreditService::class)->reverse($journal);
    $this->assertDatabaseHas('public.saldo_cargos', ['anulado' => true], 'receipt_legacy');
    $this->assertDatabaseHas('public.saldo_clientes', ['id_cliente' => 8, 'saldo' => 0], 'receipt_legacy');
    Http::assertNothingSent();
});

test('academic credits allocate initial cents between students and funding without recording fictitious revisions', function () {
    prepareReceipts($this);
    $platform = DB::connection('plataforma');
    $platform->table('public.students')->insert(['student_id' => 502, 'first_name' => 'Alumno', 'last_name' => 'Dos']);
    foreach ([501, 502] as $id) {
        $platform->table('public.saldo_pagos')->insert(['id_saldo' => $id, 'student_id' => (string) $id, 'mes' => 2, 'syear' => 2025,
            'pago' => 0, 'saldo' => 50, 'pago_beca' => 0, 'saldo_beca' => 25, 'pago_bienhechor' => 0, 'saldo_bienhechor' => 0]);
    }
    $uuid = (string) Str::uuid();
    DB::table('libreria.ventas')->insert(['id' => 10, 'cliente_id' => 1, 'operacion' => '2', 'total' => 75, 'estado' => 'GENERADO']);
    foreach ([['501', 50, 'normal'], ['502', 25, 'beca']] as $line) {
        DB::table('libreria.venta_detalles')->insert(['venta_id' => 10, 'carnet_estudiante' => $line[0], 'nombre' => 'Colegiatura', 'valor_final' => $line[1], 'cuenta_cargo' => '110201010101']);
        DB::table('libreria.venta_cuotas')->insert(['venta_id' => 10, 'saldo_id' => $line[0], 'carnet_estudiante' => $line[0], 'anio' => 2025, 'mes' => 2, 'monto' => $line[1], 'financiamiento' => $line[2], 'ambiente' => '01']);
    }
    DB::table('libreria.venta_dtes')->insert(['id' => 10, 'venta_id' => 10, 'user_id' => auth()->id(), 'codigo_generacion' => $uuid, 'numero_control' => 'DTE-CREDIT-10',
        'transmision_estado' => 'ACEPTADO', 'sello_recepcion' => 'SEAL', 'documento' => json_encode(['identificacion' => ['ambiente' => '01', 'fecEmi' => '2026-11-20'], 'resumen' => ['condicionOperacion' => 2]]),
        'cliente' => json_encode(['name' => 'Responsable', 'email' => 'responsable@example.test']), 'pagos' => json_encode(['paid' => '20.01', 'change' => '0.00'])]);
    $journal = DB::table('libreria.venta_dtes')->where('id', 10)->first();

    app(ReceiptCreditService::class)->register($journal);
    app(ReceiptCreditService::class)->register($journal);

    $this->assertDatabaseHas('public.cxc', ['student_id' => '501', 'monto' => 50, 'parciales' => 36.66], 'plataforma');
    $this->assertDatabaseHas('public.cxc', ['student_id' => '502', 'monto' => 25, 'parciales' => 18.33], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['student_id' => '501', 'saldo' => 36.66, 'saldo_beca' => 25], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['student_id' => '502', 'saldo' => 50, 'saldo_beca' => 18.33], 'plataforma');
    expect($platform->table('public.detalle_pagos')->count())->toBe(2);
    expect($platform->table('public.revisiones')->count())->toBe(0);
    app(ReceiptCreditService::class)->reverse($journal);
    $this->assertDatabaseHas('public.saldo_pagos', ['student_id' => '501', 'saldo' => 50, 'pago' => 0], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['student_id' => '502', 'saldo_beca' => 25, 'pago_beca' => 0], 'plataforma');
});

test('family and scholarship credits share one CXC without merging their obligations or blocking each other', function () {
    prepareReceipts($this);
    $platform = DB::connection('plataforma');
    $platform->table('public.saldo_pagos')->insert(['id_saldo' => 501, 'student_id' => '501', 'mes' => 2, 'syear' => 2025,
        'pago' => 0, 'saldo' => 50, 'pago_beca' => 0, 'saldo_beca' => 25, 'pago_bienhechor' => 0, 'saldo_bienhechor' => 0]);
    $journals = [];
    foreach ([10 => ['normal', 50], 11 => ['beca', 25]] as $id => [$funding, $amount]) {
        $uuid = (string) Str::uuid();
        DB::table('libreria.ventas')->insert(['id' => $id, 'cliente_id' => 1, 'operacion' => '2', 'total' => $amount, 'estado' => 'GENERADO']);
        DB::table('libreria.venta_detalles')->insert(['venta_id' => $id, 'carnet_estudiante' => '501', 'nombre' => 'Colegiatura', 'valor_final' => $amount, 'cuenta_cargo' => $funding === 'beca' ? '110201010102' : '110201010101']);
        DB::table('libreria.venta_cuotas')->insert(['venta_id' => $id, 'saldo_id' => 501, 'carnet_estudiante' => '501', 'anio' => 2025, 'mes' => 2, 'monto' => $amount, 'financiamiento' => $funding, 'ambiente' => '01']);
        DB::table('libreria.venta_dtes')->insert(['id' => $id, 'venta_id' => $id, 'user_id' => auth()->id(), 'codigo_generacion' => $uuid, 'numero_control' => 'DTE-CREDIT-'.$id,
            'transmision_estado' => 'ACEPTADO', 'sello_recepcion' => 'SEAL', 'documento' => json_encode(['identificacion' => ['ambiente' => '01', 'fecEmi' => '2026-11-20'], 'resumen' => ['condicionOperacion' => 2]]),
            'cliente' => json_encode(['name' => 'Responsable']), 'pagos' => json_encode(['paid' => '0.00', 'change' => '0.00'])]);
        $journals[$id] = DB::table('libreria.venta_dtes')->where('id', $id)->first();
        app(ReceiptCreditService::class)->register($journals[$id]);
    }
    $cxc = $platform->table('public.cxc')->sole();
    $normal = receiptAcademicPayload('academic:'.$cxc->id.':normal', '10.00');
    $scholarship = receiptAcademicPayload('academic:'.$cxc->id.':beca', '5.00');
    $scholarship['account'] = '110201010102';
    $receipt = $this->postJson(route('libreria.recibos.store'), $normal)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $secondReceipt = $this->postJson(route('libreria.recibos.store'), $scholarship)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $this->assertDatabaseHas('public.cxc', ['monto' => 75, 'parciales' => 60], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['student_id' => '501', 'saldo' => 40, 'saldo_beca' => 20], 'plataforma');
    $this->postJson($receipt->json('annulUrl'), ['reason' => 'Corregir responsable económico'])->assertOk()->assertJsonPath('status', 'ANULADO');
    $this->postJson($secondReceipt->json('annulUrl'), ['reason' => 'Corregir responsable económico'])->assertOk()->assertJsonPath('status', 'ANULADO');
    app(ReceiptCreditService::class)->reverse($journals[10]);
    $this->assertDatabaseHas('public.cxc', ['monto' => 25, 'parciales' => 25], 'plataforma');
    app(ReceiptCreditService::class)->assertAcademicNotBilled('501', 2025, 2, 'normal');
    expect(fn () => app(ReceiptCreditService::class)->assertAcademicNotBilled('501', 2025, 2, 'beca'))->toThrow(ValidationException::class);
});

test('a receipt accepts only one concept or invoice and fixed account types cannot be overridden', function () {
    prepareReceipts($this);
    DB::table('public.catalogo_recibos')->insert(['id' => 4, 'nombre_recibo' => 'ABONAR CXC', 'cuenta_abono' => '00', 'precio' => 0]);
    $free = receiptPayload();
    $free['conceptId'] = 4;
    $free['account'] = '110201010101';
    $this->postJson(route('libreria.recibos.store'), $free)->assertUnprocessable();
    $free['conceptId'] = 1;
    $this->postJson(route('libreria.recibos.store'), $free)->assertUnprocessable();
    $first = receiptLinkAcademic(receiptAcademicDebt('50.00', 2024));
    $input = receiptAcademicPayload($first, '10.00');
    $input['lines'] = [['amount' => '10.00'], ['amount' => '10.00']];
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $this->assertDatabaseCount('libreria.receipts', 0);
    unset($input['lines']);
    $input['account'] = '110204010122';
    $result = $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $this->assertDatabaseHas('public.cxc', ['parciales' => 40], 'plataforma');
    expect(DB::connection('receipt_legacy')->table('public.recibos')->sole()->cuenta_abono)->toBe('110204010122');
    $this->get($result->json('ticketUrl'))->assertOk();
});

test('the academic CXC browser searches pending invoices without treating zero as a debt', function () {
    prepareReceipts($this);
    receiptAcademicDebt('0.00', 2024);
    $pending = receiptAcademicDebt(null, 2025);
    $flagged = receiptAcademicDebt('50.00', 2023);
    $flaggedKey = receiptLinkAcademic($flagged);
    $flaggedNull = receiptAcademicDebt(null, 2022);
    DB::connection('plataforma')->table('public.cxc')->whereIn('id', [$flagged['id'], $flaggedNull['id']])->update(['recibo' => true]);
    DB::connection('plataforma')->table('public.cxc')->where('id', $pending['id'])->update(['recibo' => null, 'item_pago' => '02']);

    $this->getJson(route('libreria.recibos.cxc', ['funding' => 'normal', 'q' => '501']))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.cxcId', $pending['id'])->assertJsonPath('data.0.amount', '50')->assertJsonPath('data.0.remaining', '50.00');
    $this->getJson(route('libreria.recibos.cxc', ['funding' => 'normal', 'year' => 2024]))->assertOk()->assertJsonCount(0, 'data');
    foreach ([2022, 2023] as $year) {
        $this->getJson(route('libreria.recibos.debts', ['type' => 'alumno', 'id' => '501', 'year' => $year, 'funding' => 'normal']))->assertOk()->assertJsonCount(0, 'data');
    }
    $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload($flaggedKey))->assertUnprocessable();
    $this->assertDatabaseCount('libreria.receipts', 0);
});

test('a uniquely verified academic invoice is selected inside the form and collected without a linking screen', function () {
    prepareReceipts($this);
    $debt = receiptAcademicDebt();
    DB::connection('receipt_legacy')->table('public.productos_vendidos')->insert(['id_ventas' => 2025, 'nombre_producto' => 'Colegiatura Febrero 2025']);
    $this->getJson(route('libreria.recibos.prepare', ['mode' => 'academic_cxc', 'sourceId' => $debt['id'], 'funding' => 'normal']))
        ->assertOk()->assertJsonPath('invoice.uuid', $debt['uuid'])->assertJsonPath('account', '110201010101');
    $input = receiptAcademicPayload('academic:'.$debt['id'].':normal');
    unset($input['account']);
    $result = $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $this->assertDatabaseHas('libreria.receipt_obligations', ['linked_by' => auth()->id(), 'source_key' => 'academic:'.$debt['id'].':normal', 'remaining' => 30]);
    $this->assertDatabaseHas('public.cxc', ['id' => $debt['id'], 'parciales' => 30], 'plataforma');
    $this->get($result->json('ticketUrl'))->assertOk();
});

test('missing or ambiguous academic references block collection until a real invoice and its period are confirmed', function () {
    prepareReceipts($this);
    $debt = receiptAcademicDebt();
    $input = receiptAcademicPayload('academic:'.$debt['id'].':normal');
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $legacy = DB::connection('receipt_legacy');
    $other = (string) Str::uuid();
    $legacy->table('public.ventas')->insert(['id_venta' => 3030, 'numero_factura' => $other, 'numero_control' => 'DTE-OTRA', 'total' => 50, 'operacion' => '2', 'anulado' => false, 'n_carnet' => '501']);
    foreach ([2025, 3030] as $id) {
        $legacy->table('public.productos_vendidos')->insert(['id_ventas' => $id, 'nombre_producto' => 'Colegiatura Febrero 2025']);
    }
    $this->getJson(route('libreria.recibos.prepare', ['mode' => 'academic_cxc', 'sourceId' => $debt['id'], 'funding' => 'normal']))
        ->assertOk()->assertJsonPath('invoice', null)->assertJsonCount(2, 'invoices');
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $input['invoice'] = ['origin' => 'legacy', 'uuid' => $debt['uuid'], 'confirmed' => false];
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $this->assertDatabaseCount('libreria.receipts', 0);
    $input['invoice']['confirmed'] = true;
    $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    expect(json_decode(DB::table('libreria.receipt_obligations')->sole()->data, true)['invoice']['uuid'])->toBe($debt['uuid']);
});

test('academic accounts respect enrollment grade and specialty and missing mappings require a valid chosen account', function () {
    prepareReceipts($this);
    $debt = receiptAcademicDebt();
    $platform = DB::connection('plataforma');
    $legacy = DB::connection('receipt_legacy');
    $legacy->table('public.cuentas_cargo_reporte')->insert(['nombre' => '9° cxc', 'llave' => '110201010102']);
    $platform->table('public.school_gradelevels')->where('id', 1)->update(['title' => '9°']);
    $url = route('libreria.recibos.prepare', ['mode' => 'academic_cxc', 'sourceId' => $debt['id'], 'funding' => 'normal']);
    $this->getJson($url)->assertOk()->assertJsonPath('account', '110201010102');
    $legacy->table('public.cuentas_cargo_reporte')->insert(['nombre' => '1° Info cxc', 'llave' => '110204010122']);
    $platform->table('public.school_gradelevels')->where('id', 1)->update(['title' => '1er. Año']);
    $platform->table('public.student_enrollment')->update(['especialidad' => 'Desarrollo de Software']);
    $this->getJson($url)->assertOk()->assertJsonPath('account', '110204010122');
    $platform->table('public.student_enrollment')->update(['especialidad' => 'Sin correspondencia']);
    $this->getJson($url)->assertOk()->assertJsonPath('account', null);
    $input = receiptAcademicPayload('academic:'.$debt['id'].':normal');
    $input['invoice'] = ['origin' => 'legacy', 'uuid' => $debt['uuid'], 'confirmed' => true];
    unset($input['account']);
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $input['account'] = '00';
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $input['account'] = '110204010122';
    $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
});

test('an advance creates its own accounting identity once and recovers a lost acknowledgement without matching by name', function () {
    prepareReceipts($this);
    DB::table('libreria.clientes')->where('id', 1)->update(['nombre' => 'Responsable', 'dui' => null, 'nit' => null]);
    DB::statement("CREATE TRIGGER libreria.fail_client_ack BEFORE UPDATE ON receipt_integrations WHEN NEW.target = 'legacy' AND NEW.status = 'APLICADO' BEGIN SELECT RAISE(ABORT,'Lost acknowledgement'); END");
    $input = receiptPayload([['type' => 'cliente', 'id' => '1', 'name' => 'Responsable', 'lines' => [['conceptId' => 3, 'amount' => '15.00']], 'payments' => [['method' => '01', 'amount' => '15.00']]]]);
    $result = $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'PENDIENTE_APLICACION');
    $legacy = DB::connection('receipt_legacy');
    expect($legacy->table('public.clientes')->count())->toBe(2);
    $this->assertDatabaseCount('libreria.receipt_client_links', 0);
    DB::statement('DROP TRIGGER libreria.fail_client_ack');
    $this->postJson($result->json('retryUrl'))->assertOk()->assertJsonPath('status', 'EMITIDO');
    $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('id', $result->json('id'));
    $input['requestKey'] = (string) Str::uuid();
    $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    expect($legacy->table('public.clientes')->count())->toBe(2);
    $this->assertDatabaseHas('libreria.receipt_client_links', ['client_id' => 1, 'legacy_client_id' => 9]);
    $this->assertDatabaseHas('public.saldo_clientes', ['id_cliente' => 9, 'saldo' => -30], 'receipt_legacy');
    expect($legacy->table('public.recibos')->count())->toBe(2);
});

test('contradictory customer documents block an advance before recording money or another identity', function () {
    prepareReceipts($this);
    DB::table('libreria.clientes')->where('id', 1)->update(['nit' => '0614-123456-101-0']);
    DB::connection('receipt_legacy')->table('public.clientes')->insert(['id' => 9, 'nombre' => 'Otra persona', 'nit' => '06141234561010']);
    $input = receiptPayload([['type' => 'cliente', 'id' => '1', 'lines' => [['conceptId' => 3, 'amount' => '15.00']], 'payments' => [['method' => '01', 'amount' => '15.00']]]]);
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $this->assertDatabaseCount('libreria.receipts', 0);
    expect(DB::connection('receipt_legacy')->table('public.clientes')->count())->toBe(2);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->count())->toBe(0);
});

test('legacy family scholarship and sponsor collections preserve separate obligations and hide each settled funding balance', function () {
    prepareReceipts($this);
    $debt = receiptAcademicDebt('100.00');
    $platform = DB::connection('plataforma');
    $legacy = DB::connection('receipt_legacy');
    $platform->table('public.cxc')->where('id', $debt['id'])->update(['monto' => 100]);
    $platform->table('public.saldo_pagos')->where('id_saldo', 2025)->update(['pago' => 0, 'saldo' => 50, 'pago_beca' => 0, 'saldo_beca' => 30, 'pago_bienhechor' => 0, 'saldo_bienhechor' => 20]);
    $legacy->table('public.ventas')->where('id_venta', 2025)->update(['total' => 100]);
    $legacy->table('public.productos_vendidos')->insert(['id_ventas' => 2025, 'nombre_producto' => 'Colegiatura Febrero 2025']);
    foreach (['normal', 'beca', 'bienhechor'] as $funding) {
        $input = receiptAcademicPayload('academic:'.$debt['id'].':'.$funding, '10.00');
        if ($funding !== 'normal') {
            $input['invoice'] = ['origin' => 'legacy', 'uuid' => $debt['uuid'], 'confirmed' => true];
        }
        $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    }
    $this->assertDatabaseHas('public.cxc', ['monto' => 100, 'parciales' => 70, 'recibo' => false], 'plataforma');
    $this->assertDatabaseHas('public.saldo_pagos', ['saldo' => 40, 'saldo_beca' => 20, 'saldo_bienhechor' => 10], 'plataforma');
    expect(DB::table('libreria.receipt_obligations')->orderBy('id')->pluck('amount')->map(fn ($amount) => (float) $amount)->all())->toEqual([50, 30, 20]);
    expect($platform->table('public.detalle_pagos')->where('pago_beca', true)->count())->toBe(1);
    expect($platform->table('public.detalle_pagos')->where('pago_bienhechor', true)->count())->toBe(1);
    $this->postJson(route('libreria.recibos.store'), receiptAcademicPayload('academic:'.$debt['id'].':normal', '40.00'))->assertCreated();
    $this->getJson(route('libreria.recibos.cxc', ['funding' => 'normal']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(route('libreria.recibos.cxc', ['funding' => 'beca']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.remaining', 20);
    expect($platform->table('public.revisiones')->count())->toBe(0);
});

test('several academic CXC share one receipt and preserve mixed payment cents retries and complete annulment', function () {
    prepareReceipts($this);
    $first = receiptAcademicDebt('50.00', 2024);
    $second = receiptAcademicDebt('50.00', 2025);
    receiptLinkAcademic($first);
    receiptLinkAcademic($second);
    $input = receiptAcademicPayload('academic:'.$first['id'].':normal', '0.02');
    unset($input['sourceId']);
    $input['academicItems'] = array_map(fn ($debt) => ['sourceId' => $debt['id'], 'funding' => 'normal', 'account' => '110201010101', 'amount' => '0.01'], [$first, $second]);
    $input['payments'] = [['method' => '01', 'amount' => '0.01'], ['method' => '02', 'amount' => '0.01']];
    $result = $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('status', 'EMITIDO');
    $this->postJson(route('libreria.recibos.store'), $input)->assertCreated()->assertJsonPath('id', $result->json('id'));
    $this->assertDatabaseCount('libreria.receipts', 1);
    $this->assertDatabaseCount('libreria.receipt_lines', 2);
    expect(DB::connection('plataforma')->table('public.cxc')->where('parciales', 49.99)->count())->toBe(2);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->where('tarjeta', true)->sum('precio'))->toEqual(0.01);
    $this->get($result->json('ticketUrl'))->assertOk();
    $this->postJson($result->json('annulUrl'), ['reason' => 'Corregir ambos abonos'])->assertOk()->assertJsonPath('status', 'ANULADO');
    expect(DB::connection('plataforma')->table('public.cxc')->where('parciales', 50)->count())->toBe(2);
});

test('a multiple CXC receipt rejects overpayments duplicates and different students without partial reservations', function () {
    prepareReceipts($this);
    $first = receiptAcademicDebt('50.00', 2024);
    $second = receiptAcademicDebt('50.00', 2025);
    receiptLinkAcademic($first);
    $input = receiptAcademicPayload('academic:'.$first['id'].':normal', '52.00');
    unset($input['sourceId']);
    $one = ['sourceId' => $first['id'], 'funding' => 'normal', 'account' => '110201010101', 'amount' => '1.00'];
    $two = ['sourceId' => $second['id'], 'funding' => 'normal', 'account' => '110201010101', 'amount' => '51.00', 'invoice' => ['origin' => 'legacy', 'uuid' => $second['uuid'], 'confirmed' => true]];
    $input['academicItems'] = [$one, $two];
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $input['academicItems'] = [$one, $one];
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    DB::connection('plataforma')->table('public.students')->insert(['student_id' => 502, 'first_name' => 'Otro', 'last_name' => 'Alumno']);
    DB::connection('plataforma')->table('public.cxc')->where('id', $second['id'])->update(['student_id' => '502']);
    DB::connection('plataforma')->table('public.saldo_pagos')->where('id_saldo', 2025)->update(['student_id' => '502']);
    DB::connection('receipt_legacy')->table('public.ventas')->where('id_venta', 2025)->update(['n_carnet' => '502']);
    $two['amount'] = '1.00';
    $input['amount'] = '2.00';
    $input['payments'] = [['method' => '01', 'amount' => '2.00']];
    $input['academicItems'] = [$one, $two];
    $this->postJson(route('libreria.recibos.store'), $input)->assertUnprocessable();
    $this->assertDatabaseCount('libreria.receipts', 0);
    $this->assertDatabaseHas('libreria.receipt_obligations', ['source_key' => 'academic:'.$first['id'].':normal', 'remaining' => 50]);
    $this->assertDatabaseCount('libreria.receipt_obligations', 1);
    expect(DB::connection('receipt_legacy')->table('public.recibos')->count())->toBe(0);
});
