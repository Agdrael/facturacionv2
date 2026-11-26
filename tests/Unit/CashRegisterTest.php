<?php

use App\Models\Libreria\CashReconciliation;
use App\Models\Libreria\CashSession;
use App\Models\Role;
use App\Models\User;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\CashSummaryService;
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
use PhpOffice\PhpSpreadsheet\Style\Protection;
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
        'employee-access.allowed_domain' => 'example.test', 'services.factec.transmission_enabled' => false, 'receipts.enabled' => false]);
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
function cashEditedWorkbook(CashReconciliation $record, array $changes = [], ?Closure $edit = null): UploadedFile
{
    $path = app(CashWorkbookService::class)->original($record, auth()->user());
    $book = IOFactory::load(Storage::disk('cash')->path($path));
    foreach ($changes as $cell => $value) {
        $book->getSheetByName('Arqueo')->setCellValue($cell, $value);
    }
    if ($edit !== null) {
        $edit($book);
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
    $reports = app(CashSummaryService::class);
    $summary = $reports->summary($record, 'ventas');
    expect($summary)->toMatchArray(['count' => 2, 'total' => '113.00', 'collected' => '66.50', 'credit' => '46.50',
        'methods' => [['code' => '01', 'name' => 'Efectivo', 'amount' => '46.50'], ['code' => '03', 'name' => 'Tarjeta crédito', 'amount' => '20.00']]]);
    expect($summary['operators'])->toContain('Operador', 'Segundo operador');
    $this->get(route('libreria.arqueos.summary', [$record->id, 'ventas']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk()->assertSee('Resumen ventas')->assertSee('Resumen recibos');
    DB::table('libreria.ventas')->where('id', 1)->update(['estado' => 'ANULADO', 'total' => '0.00']);
    expect($reports->summary($record->fresh(), 'ventas'))->toBe($summary);
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
    $this->get(route('libreria.arqueos.summary', [$record->id, 'ventas']))->assertForbidden();
    $this->get(route('libreria.arqueos.summary', [$record->id, 'recibos']))->assertForbidden();
    $cashier->setAttribute('puntoventa', 'P001');
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk();
    $this->get(route('libreria.arqueos.summary', [$record->id, 'ventas']))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get(route('libreria.arqueos.summary', [$record->id, 'recibos']))->assertOk();
    $this->get('/libreria/arqueos/'.$record->id.'/resumenes/invalido')->assertNotFound();
    $role = Role::query()->where('code', 'libreria')->sole();
    $download = $role->permissions()->where('code', 'libreria.arqueos.descargar')->sole();
    $role->permissions()->detach($download->id);
    $this->get(route('libreria.arqueos.summary', [$record->id, 'ventas']))->assertForbidden();
    $this->get(route('libreria.arqueos.summary', [$record->id, 'recibos']))->assertForbidden();
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk()->assertDontSee('Resúmenes para imprimir');
    $this->get(route('libreria.cajas.code', $record->sesion_id))->assertForbidden();
    $this->post(route('libreria.arqueos.review', $record->id))->assertForbidden();
    $this->post(route('libreria.arqueos.sign', $record->id))->assertForbidden();
    $this->actingAs($admin);
    $admin->setAttribute('puntoventa', 'P004');
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk();
    $this->get(route('libreria.cajas.code', $record->sesion_id))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

test('closed session summaries distinguish no movements from missing historical receipt data', function () {
    prepareCashControl($this);
    $cash = app(CashRegisterService::class);
    $session = $cash->open(auth()->user());
    $record = $cash->close(auth()->user(), $session->id);
    foreach (['ventas', 'recibos'] as $kind) {
        expect(app(CashSummaryService::class)->summary($record, $kind))->toMatchArray(['count' => 0, 'total' => '0.00', 'collected' => '0.00', 'methods' => []]);
        $this->get(route('libreria.arqueos.summary', [$record->id, $kind]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
    $oldSnapshot = $record->snapshot;
    $record->update(['snapshot' => array_replace($oldSnapshot, ['total' => '1.00'])]);
    $this->getJson(route('libreria.arqueos.summary', [$record->id, 'ventas']))->assertUnprocessable()
        ->assertJsonPath('message', 'Los importes guardados en este cierre requieren revisión antes de generar el resumen.');
    unset($oldSnapshot['receipts'], $oldSnapshot['receiptTotal']);
    $record->update(['snapshot' => $oldSnapshot]);
    $this->getJson(route('libreria.arqueos.summary', [$record->id, 'recibos']))->assertUnprocessable()->assertSee('Este cierre no conserva');
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk()->assertSee('Este cierre no conserva datos de recibos');
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

test('cash workbook subtracts partial remittances and accepts counted third party checks without changing expected amounts', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $changes = ['D5' => 500, 'D21' => 10, 'D22' => 20, 'D23' => 25, 'D24' => 50, 'D25' => 5.75,
        'K29' => 120, 'K30' => 30, 'C35' => 'CH-001', 'D35' => 'Beneficiario', 'K35' => 200, 'K36' => 100];
    $file = cashEditedWorkbook($record, $changes);
    $book = IOFactory::load($file->getPathname());
    $sheet = $book->getSheetByName('Arqueo');
    expect($sheet->getCell('K10')->getCalculatedValue())->toEqual(464.25);
    expect($sheet->getCell('K11')->getCalculatedValue())->toEqual(125);
    expect($sheet->getCell('K12')->getCalculatedValue())->toEqual(250);
    expect($sheet->getCell('K13')->getCalculatedValue())->toEqual(839.25);
    expect($sheet->getCell('K38')->getCalculatedValue())->toEqual(300);
    expect($sheet->getCell('F48')->getCalculatedValue())->toEqual(950);
    expect($sheet->getCell('J48')->getValue())->toEqual(0);
    expect($sheet->getStyle('K35')->getProtection()->getLocked())->toBe(Protection::PROTECTION_UNPROTECTED);
    $book->disconnectWorksheets();
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $file])->assertSessionHasNoErrors();
    expect($record->fresh()->snapshot)->toBe($record->snapshot);
    foreach ([['K13' => 1], ['K35' => -1], ['K35' => '=1+1']] as $invalid) {
        $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record, $invalid)])
            ->assertSessionHasErrors('archivo');
    }
});

test('cash workbook includes every recorded check when its initial detail exceeds three rows', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $snapshot = $record->snapshot;
    $sample = $snapshot['sales'][0];
    $snapshot['sales'] = [];
    foreach ([10, 20, 30, 40] as $index => $amount) {
        $snapshot['sales'][] = array_replace($sample, ['number' => 'CHEQUE-'.($index + 1), 'total' => (string) $amount, 'payments' => ['04' => (string) $amount]]);
    }
    $snapshot['methods'] = ['04' => '100.00'];
    $snapshot['total'] = '100.00';
    $snapshot['documents'] = ['01' => '100.00'];
    $record->snapshot = $snapshot;
    $book = app(CashWorkbookService::class)->build($record);
    $sheet = $book->getSheetByName('Arqueo');
    expect($sheet->getCell('K38')->getCalculatedValue())->toEqual(100);
    expect($sheet->getCell('K37')->getValue())->toEqual(70);
    expect($sheet->getCell('J48')->getValue())->toEqual(100);
    expect($sheet->getCell('C37')->getValue())->toBe('2 cheques (ver Resumen)');
    $book->disconnectWorksheets();
});

test('Excel accepts extra check rows and editable sums while preserving the closed amounts', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $file = cashEditedWorkbook($record, [], function ($book): void {
        $sheet = $book->getSheetByName('Arqueo');
        $sheet->insertNewRowBefore(32, 2);
        $sheet->insertNewRowBefore(40, 4);
        $sheet->setCellValue('D5', 100);
        foreach ([10, 20, 30, 40, 50] as $offset => $amount) {
            $sheet->setCellValue('C'.(29 + $offset), 'PROPIO-'.$offset);
            $sheet->setCellValue('K'.(29 + $offset), $amount);
        }
        foreach (range(1, 7) as $offset => $amount) {
            $sheet->setCellValue('C'.(37 + $offset), 'AJENO-'.$offset);
            $sheet->setCellValue('K'.(37 + $offset), $amount);
        }
        $sheet->setCellValue('K34', '=SUM(K29:K33)');
        $sheet->setCellValue('K44', '=SUM($K$37:$K$43)');
    });
    $book = IOFactory::load($file->getPathname());
    $sheet = $book->getSheetByName('Arqueo');
    expect($sheet->getProtection()->getInsertRows())->toBeFalse();
    expect($sheet->getCell('K34')->getCalculatedValue())->toEqual(150);
    expect($sheet->getCell('K44')->getCalculatedValue())->toEqual(28);
    expect($sheet->getCell('K13')->getCalculatedValue())->toEqual(278);
    expect($sheet->getCell('D54')->getValue())->toEqual(56.50);
    $book->disconnectWorksheets();
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record, ['K32' => 150, 'K38' => 28])])->assertSessionHasNoErrors();
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $file])->assertSessionHasNoErrors();
    expect($record->fresh()->snapshot)->toBe($record->snapshot);
    $record->refresh();
    $version = hash('sha256', $record->entregado_path);
    $this->post(route('libreria.arqueos.review', $record->id), ['version' => $version])->assertSessionHasNoErrors();
    $this->post(route('libreria.arqueos.sign', $record->id), ['version' => $version, 'firma' => cashTestSignature(), 'confirmed' => '1'])->assertSessionHasNoErrors();
    $record->refresh();
    $signed = IOFactory::load(Storage::disk('cash')->path($record->firmado_path));
    expect($signed->getSheetByName('Arqueo')->getCell('K44')->getCalculatedValue())->toEqual(28);
    expect($record->estado)->toBe('firmado');
    $signed->disconnectWorksheets();
});

test('Excel rejects added rows outside checks and sum references outside their own check block', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $outside = cashEditedWorkbook($record, [], fn ($book) => $book->getSheetByName('Arqueo')->insertNewRowBefore(20));
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $outside])->assertSessionHasErrors('archivo');
    foreach ([['K32' => '=SUM(K6:K9)'], ['K38' => '=SUM(K29:K31)'], ['K38' => -1], ['D48' => 1]] as $invalid) {
        $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record, $invalid)])->assertSessionHasErrors('archivo');
    }
    $tampered = cashEditedWorkbook($record, [], function ($book): void {
        $sheet = $book->getSheetByName('Arqueo');
        $sheet->insertNewRowBefore(38, 3);
        $sheet->setCellValue('D51', 1);
    });
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $tampered])->assertSessionHasErrors('archivo');
});

test('updating an unsigned workbook preserves previous versions and entered data and requires a new delivery', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $files = app(CashWorkbookService::class);
    $oldPath = $files->original($record, auth()->user());
    $book = IOFactory::load(Storage::disk('cash')->path($oldPath));
    $book->getSheetByName('_Control')->setCellValue('A4', '2');
    $book->getSheetByName('Arqueo')->setCellValue('K13', '=SUM(K10:K12)');
    $book->getSheetByName('Arqueo')->setCellValue('K38', 0);
    $writer = new Xlsx($book);
    $writer->setPreCalculateFormulas(false);
    $writer->save(Storage::disk('cash')->path($oldPath));
    $book->disconnectWorksheets();
    $oldUpload = cashEditedWorkbook($record, ['D5' => 6, 'K29' => 120, 'K35' => 9.50, 'C35' => 'CHEQUE-MANUAL', 'C50' => 'Datos ya entregados']);
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $oldUpload])->assertSessionHasNoErrors();
    $record->refresh();
    $deliveredPath = $record->entregado_path;
    $this->post(route('libreria.arqueos.review', $record->id), ['version' => hash('sha256', $deliveredPath)])->assertSessionHasNoErrors();
    $this->post(route('libreria.arqueos.template', $record->id))->assertRedirect()->assertSessionHasNoErrors();
    $record->refresh();
    expect($record->original_path)->not->toBe($oldPath);
    expect($record->estado)->toBe('pendiente_excel');
    expect($record->entregado_path)->toBeNull();
    expect($record->revisado_at)->toBeNull();
    expect($record->archivos)->toHaveCount(3);
    Storage::disk('cash')->assertExists([$oldPath, $deliveredPath, $record->original_path]);
    $book = IOFactory::load(Storage::disk('cash')->path($record->original_path));
    $sheet = $book->getSheetByName('Arqueo');
    expect($sheet->getCell('D5')->getValue())->toEqual(6);
    expect($sheet->getCell('K29')->getValue())->toEqual(120);
    expect($sheet->getCell('K35')->getValue())->toEqual(9.50);
    expect($sheet->getCell('C35')->getValue())->toBe('CHEQUE-MANUAL');
    expect($sheet->getCell('C50')->getValue())->toBe('Datos ya entregados');
    expect($sheet->getCell('K13')->getValue())->toBe('=SUM(K10:K12)');
    expect($sheet->getStyle('K35')->getProtection()->getLocked())->toBe(Protection::PROTECTION_UNPROTECTED);
    $book->disconnectWorksheets();
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $oldUpload])->assertSessionHasErrors('archivo');
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => cashEditedWorkbook($record, ['K35' => 9.50])])->assertSessionHasNoErrors();
    $record->refresh();
    $newPath = $record->original_path;
    $count = count($record->archivos);
    $this->post(route('libreria.arqueos.template', $record->id))->assertSessionHasNoErrors();
    expect($record->fresh()->original_path)->toBe($newPath);
    expect($record->fresh()->estado)->toBe('recibido');
    expect($record->fresh()->archivos)->toHaveCount($count);
    auth()->user()->roles()->sync([Role::query()->where('code', 'libreria')->sole()->id]);
    auth()->user()->setAttribute('puntoventa', 'P002');
    $this->post(route('libreria.arqueos.template', $record->id))->assertForbidden();
    expect($record->fresh()->archivos)->toHaveCount($count);
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
    $this->post(route('libreria.arqueos.template', $record->id))->assertSessionHasErrors('archivo');
    expect($record->fresh()->archivos)->toHaveCount(4);
    $this->get(route('libreria.arqueos.download', [$record->id, 'firmado']))->assertOk();
});

test('refreshing revision four corrects cash and counted total while retaining manually added check rows and sums', function () {
    prepareCashControl($this);
    $record = cashClosedRecord($this);
    $oldPath = app(CashWorkbookService::class)->original($record, auth()->user());
    $book = IOFactory::load(Storage::disk('cash')->path($oldPath));
    $book->getSheetByName('_Control')->setCellValue('A4', '4');
    $book->getSheetByName('Arqueo')->setCellValue('K10', '=SUM(K6,K9)');
    $book->getSheetByName('Arqueo')->setCellValue('K13', '=SUM(K10:K12)-SUM(K21:K22)');
    $book->getSheetByName('Arqueo')->setCellValue('F48', '=SUM(K13,K26)');
    $writer = new Xlsx($book);
    $writer->setPreCalculateFormulas(false);
    $writer->save(Storage::disk('cash')->path($oldPath));
    $book->disconnectWorksheets();
    $upload = cashEditedWorkbook($record, [], function ($book): void {
        $sheet = $book->getSheetByName('Arqueo');
        $sheet->insertNewRowBefore(38, 2);
        $sheet->setCellValue('D5', 100);
        $sheet->setCellValue('D21', 2);
        $sheet->setCellValue('D22', 3);
        $sheet->setCellValue('D25', 7.50);
        $sheet->setCellValue('K35', 5);
        $sheet->setCellValue('K39', 10);
        $sheet->setCellValue('K40', '=SUM(K35:K39)');
    });
    $this->post(route('libreria.arqueos.upload', $record->id), ['archivo' => $upload])->assertSessionHasNoErrors();
    $this->post(route('libreria.arqueos.template', $record->id))->assertSessionHasNoErrors();
    $record->refresh();
    $book = IOFactory::load(Storage::disk('cash')->path($record->original_path));
    $sheet = $book->getSheetByName('Arqueo');
    expect($sheet->getCell('K25')->getCalculatedValue())->toEqual(7.50);
    expect($sheet->getCell('K39')->getValue())->toEqual(10);
    expect($sheet->getCell('K40')->getValue())->toBe('=SUM(K35:K39)');
    expect($sheet->getCell('K10')->getCalculatedValue())->toEqual(87.50);
    expect($sheet->getCell('K13')->getValue())->toBe('=SUM(K10:K12)');
    expect($sheet->getCell('K13')->getCalculatedValue())->toEqual(102.50);
    expect($sheet->getCell('F50')->getCalculatedValue())->toEqual(115);
    expect($sheet->getCell('F47')->getValue())->toBe('EFECTIVO + CHEQUES');
    expect($book->getSheetByName('_Control')->getCell('A4')->getValue())->toBe('5');
    expect($record->estado)->toBe('pendiente_excel');
    $book->disconnectWorksheets();
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
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk()->assertSee('Incidencias posteriores al cierre');
    expect($record->fresh()->snapshot)->toBe($snapshot);
});
