<?php

use App\Models\Libreria\CashReconciliation;
use App\Models\Libreria\CashSession;
use App\Models\User;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\CashWorkbookService;
use Carbon\CarbonImmutable;
use Database\Seeders\CashControlSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/DteDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    $this->withoutVite();
});

function prepareCashControl(TestCase $test, string $point = 'P001'): User
{
    config(['database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'employee-access.allowed_domain' => 'example.test']);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $test->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00', 'America/El_Salvador'));
    prepareDteGeneration($test);
    (require __DIR__.'/../../database/migrations/2026_11_18_135412_create_cash_control_tables.php')->up();
    $test->seed(CashControlSeeder::class);
    Storage::fake('cash');
    Http::preventStrayRequests();
    $user = auth()->user();
    $user->setAttribute('puntoventa', $point);
    $remote = DB::connection('dtesantacecilia');
    $remote->statement('CREATE TABLE public.empleados (empleado_id TEXT PRIMARY KEY, codigo TEXT, correo TEXT)');
    $remote->table('public.empleados')->insert(['empleado_id' => 'E7', 'codigo' => 'EMP7', 'correo' => $user->email]);
    RateLimiter::clear('cash-reopen:'.$user->id.':'.$point);

    return $user;
}

function cashClosedRecord(TestCase $test): CashReconciliation
{
    $cash = app(CashRegisterService::class);
    $session = $cash->open(auth()->user());
    $test->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated();

    return $cash->close(auth()->user(), $session->id);
}

/** @param array<string, mixed> $changes */
function cashEditedWorkbook(CashReconciliation $record, array $changes = []): UploadedFile
{
    $path = app(CashWorkbookService::class)->original($record, auth()->user());
    $book = IOFactory::load(Storage::disk('cash')->path($path));
    foreach ($changes as $cell => $value) {
        $book->getSheetByName('Arqueo')->setCellValue($cell, $value);
    }
    $path = tempnam(sys_get_temp_dir(), 'cash-test-');
    $writer = new Xlsx($book);
    $writer->setPreCalculateFormulas(false);
    $writer->save($path);
    $content = file_get_contents($path);
    unlink($path);
    $book->disconnectWorksheets();

    return UploadedFile::fake()->createWithContent('arqueo.xlsx', $content);
}

function cashTestSignature(): string
{
    $image = imagecreatetruecolor(720, 240);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 70, 100, 600, 160, imagecolorallocate($image, 10, 10, 10));
    ob_start();
    imagepng($image);
    $png = ob_get_clean();

    return 'data:image/png;base64,'.base64_encode($png);
}

test('controlled registers block new sales until opening and repeated closes preserve one snapshot', function (string $point) {
    $user = prepareCashControl($this, $point);
    $this->get(route('libreria.venta'))->assertOk()->assertSee('La caja está cerrada');
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertUnprocessable()->assertJsonValidationErrors('caja');
    $this->assertDatabaseCount('libreria.ventas', 0);
    $this->post(route('libreria.cajas.open'))->assertRedirect(route('libreria.cajas.index'));
    $session = CashSession::query()->sole();
    $result = $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated();
    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'caja_sesion_id' => $session->id, 'caja' => $point]);
    $this->post(route('libreria.cajas.close', $session->id), ['confirmed' => '1'])->assertRedirect();
    $record = CashReconciliation::query()->sole();
    expect($record->snapshot['total'])->toBe('56.50')->and($record->snapshot['sales'])->toHaveCount(1);
    $this->post(route('libreria.cajas.close', $session->id), ['confirmed' => '1'])->assertRedirect(route('libreria.arqueos.show', $record->id));
    $this->assertDatabaseCount('libreria.arqueos', 1);
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated()->assertJsonPath('uuid', $result->json('uuid'));
    $new = dteSalePayload();
    $new['requestKey'] = (string) Str::uuid();
    $this->postJson(route('libreria.ventas.store'), $new)->assertUnprocessable()->assertJsonValidationErrors('caja');
    $this->get(route('libreria.cajas.index'))->assertOk()->assertSee('Pendiente de Excel');
    Http::assertNothingSent();
})->with(['P001', 'P002', 'P003']);

test('P004 keeps generating sales without a cash session', function () {
    prepareCashControl($this, 'P004');
    $this->postJson(route('libreria.ventas.store'), dteSalePayload())->assertCreated();
    $this->assertDatabaseCount('libreria.caja_sesiones', 0);
    $this->assertDatabaseHas('libreria.ventas', ['id' => 1, 'caja_sesion_id' => null, 'caja' => 'P004']);
});

test('a snapshot reconciles mixed payments change credit balances and all operators while excluding annulled sales', function () {
    prepareCashControl($this);
    DB::connection('dtesantacecilia')->table('public.CAT-017')->insert(['codigo' => '03', 'valores' => 'Tarjeta de crédito']);
    $cash = app(CashRegisterService::class);
    $session = $cash->open(auth()->user());
    $first = dteSalePayload();
    $first['payments'] = [['method' => '01', 'amount' => '40.00', 'reference' => null], ['method' => '03', 'amount' => '20.00', 'reference' => 'POS-123']];
    $this->postJson(route('libreria.ventas.store'), $first)->assertCreated();
    foreach ([2, 3] as $id) {
        $input = dteSalePayload();
        $input['requestKey'] = (string) Str::uuid();
        $this->postJson(route('libreria.ventas.store'), $input)->assertCreated();
    }
    DB::table('public.users')->insert(['id' => 8, 'name' => 'Segundo operador']);
    DB::table('libreria.venta_dtes')->where('venta_id', 2)->update(['user_id' => 8, 'transmision_estado' => 'RECHAZADO',
        'pagos' => json_encode(['received' => [['method' => '01', 'amount' => '10.00']], 'paid' => '10.00', 'change' => '0.00'])]);
    DB::table('libreria.ventas')->where('id', 2)->update(['operacion' => '2']);
    DB::table('libreria.ventas')->where('id', 3)->update(['estado' => 'ANULADO']);
    $record = $cash->close(auth()->user(), $session->id);
    expect($record->snapshot)->toMatchArray(['total' => '113.00', 'receivable' => '46.50', 'methods' => ['01' => '46.50', '03' => '20.00']]);
    expect(array_column($record->snapshot['sales'], 'operator'))->toContain('Operador', 'Segundo operador');
    expect(array_column($record->snapshot['sales'], 'dteState'))->toContain('PENDIENTE', 'RECHAZADO');
});

test('reopening binds a single use code to the latest closure and creates an independent session', function () {
    $user = prepareCashControl($this);
    $cash = app(CashRegisterService::class);
    $first = cashClosedRecord($this);
    $first->session->update(['codigo_reapertura' => '111111']);
    $user->setAttribute('puntoventa', 'P002');
    $other = $cash->open($user);
    $second = $cash->close($user, $other->id);
    $second->session->update(['codigo_reapertura' => '222222']);
    $this->postJson(route('libreria.cajas.reopen'), ['codigo' => '111111', 'motivo' => 'Venta adicional'])->assertUnprocessable();
    $this->post(route('libreria.cajas.reopen'), ['codigo' => '222222', 'motivo' => 'Venta adicional'])->assertRedirect();
    $session = CashSession::query()->whereNull('cerrado_at')->sole();
    expect($session->sesion_anterior_id)->toBe($other->id)->and($session->motivo_reapertura)->toBe('Venta adicional');
    expect($second->session->fresh()->codigo_usado_at)->not->toBeNull();
    $newRecord = $cash->close($user, $session->id);
    expect($newRecord->snapshot['sales'])->toHaveCount(0)->and($first->fresh()->snapshot['total'])->toBe('56.50');
    $this->postJson(route('libreria.cajas.reopen'), ['codigo' => '222222', 'motivo' => 'Venta adicional'])->assertUnprocessable();
});

test('reopening allows five attempts per minute and the next jornada opens normally', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $record->session->update(['codigo_reapertura' => '123456']);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('libreria.cajas.reopen'), ['codigo' => '000000', 'motivo' => 'Venta adicional'])->assertUnprocessable();
    }
    $this->postJson(route('libreria.cajas.reopen'), ['codigo' => '123456', 'motivo' => 'Venta adicional'])
        ->assertUnprocessable()->assertJsonValidationErrors('codigo')->assertSee('Demasiados intentos');
    $this->travelTo(now()->addDay());
    $this->post(route('libreria.cajas.open'))->assertRedirect();
    expect(CashSession::query()->whereNull('cerrado_at')->sole()->fecha_operativa->toDateString())->toBe('2026-11-12');
});

test('libreria is limited to its assigned caja and cannot read codes review or sign', function () {
    $admin = prepareCashControl($this);
    $record = cashClosedRecord($this);
    $cashier = User::factory()->make(['id' => 88, 'email' => 'cajero@example.test']);
    grantTestRoles($cashier, ['libreria']);
    $cashier->setAttribute('puntoventa', 'P002');
    $this->actingAs($cashier);
    $this->get(route('libreria.arqueos.show', $record->id))->assertForbidden();
    $this->get(route('libreria.arqueos.download', [$record->id, 'original']))->assertForbidden();
    $cashier->setAttribute('puntoventa', 'P001');
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk();
    $this->get(route('libreria.cajas.code', $record->sesion_id))->assertForbidden();
    $this->post(route('libreria.arqueos.review', $record->id))->assertForbidden();
    $this->post(route('libreria.arqueos.sign', $record->id))->assertForbidden();
    $this->actingAs($admin);
    $admin->setAttribute('puntoventa', 'P004');
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk();
    $this->get(route('libreria.cajas.code', $record->sesion_id))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

test('Excel delivery preserves system totals while permitting physical counts and observations', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $files = app(CashWorkbookService::class);
    $this->get(route('libreria.arqueos.download', [$record->id, 'original']))->assertOk();
    $file = cashEditedWorkbook($record, ['D5' => 6, 'C50' => 'Observación de prueba']);
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $file])->assertRedirect()->assertSessionHasNoErrors();
    expect($record->fresh()->estado)->toBe('recibido');
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record, ['D48' => 1])])
        ->assertSessionHasErrors('archivo');
    expect($record->fresh()->archivos)->toHaveCount(2);
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record, ['D5' => '=1+1'])])
        ->assertSessionHasErrors('archivo');
});

test('review and signature apply to the delivered version and signed workbooks cannot be replaced', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record, ['D5' => 6])])->assertSessionHasNoErrors();
    $record->refresh();
    $version = hash('sha256', $record->entregado_path);
    $this->post(route('libreria.arqueos.review', $record->id), ['version' => str_repeat('0', 64)])->assertSessionHasErrors('arqueo');
    $this->post(route('libreria.arqueos.review', $record->id), ['version' => $version])->assertSessionHasNoErrors();
    $this->post(route('libreria.arqueos.sign', $record->id), ['version' => $version, 'firma' => cashTestSignature(), 'confirmed' => '1'])->assertSessionHasNoErrors();
    $record->refresh();
    expect($record->estado)->toBe('firmado')->and($record->firmado_empleado)->toBe('EMP7')->and($record->archivos)->toHaveCount(4);
    $book = IOFactory::load(Storage::disk('cash')->path($record->firmado_path));
    expect($book->getSheetByName('Arqueo')->getDrawingCollection()->count())->toBeGreaterThan(0);
    $book->disconnectWorksheets();
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record)])->assertSessionHasErrors('archivo');
    $this->get(route('libreria.arqueos.download', [$record->id, 'firmado']))->assertOk();
});

test('a failed Excel export never reopens or duplicates the closed session and can be retried', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $template = config('cash.template');
    config(['cash.template' => 'missing-template.xlsx']);
    $this->get(route('libreria.arqueos.download', [$record->id, 'original']))->assertInternalServerError();
    expect($record->session->fresh()->cerrado_at)->not->toBeNull();
    $this->assertDatabaseCount('libreria.arqueos', 1);
    config(['cash.template' => $template]);
    $this->get(route('libreria.arqueos.download', [$record->id, 'original']))->assertOk();
});

test('later invalidations appear as incidents without changing the closed snapshot', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $snapshot = $record->snapshot;
    DB::table('libreria.venta_invalidaciones')->insert(['venta_dte_id' => 1, 'aplicada_at' => now()->addMinute()->toDateTimeString()]);
    DB::table('libreria.ventas')->where('id', 1)->update(['estado' => 'ANULADO']);
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk()->assertSee('Invalidaciones posteriores al cierre');
    expect($record->fresh()->snapshot)->toBe($snapshot);
});
