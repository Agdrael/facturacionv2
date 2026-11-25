<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\Libreria\CashReconciliation;
use App\Models\Role;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\CashSummaryService;
use App\Services\Libreria\CashWorkbookService;
use App\Services\Libreria\LegacyCashBridgeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/LegacyCashDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    $this->withoutVite();
});

afterEach(function () {
    if (isset($this->legacyDatabase, $this->legacyAdmin) && preg_match('/^codex_cash_bridge_[a-f0-9]{16}$/', $this->legacyDatabase)) {
        DB::purge('legacy_bridge_test');
        DB::purge('legacy_bridge_competing');
        DB::purge('dtesantacecilia');
        $this->legacyAdmin->exec('DROP DATABASE '.$this->legacyDatabase.' WITH (FORCE)');
    }
});

test('joint closure requires initial receipt confirmation and imports each source once', function () {
    $user = prepareLegacyCashDatabase($this);
    $sale = legacyCashSale();
    $receipt = legacyCashReceipt(['tarjeta' => true]);
    $cash = app(CashRegisterService::class);
    $native = $cash->open($user);
    config(['receipts.enabled' => true]);
    DB::unprepared('CREATE TABLE libreria.receipts (id bigserial PRIMARY KEY, cash_session_id bigint, status text, total numeric(18,2), change numeric(18,2),
        recipient_name text, operator_name text, annulled_at timestamptz);
        CREATE TABLE libreria.receipt_payments (id bigserial PRIMARY KEY, receipt_id bigint, method text, amount numeric(18,2), reference text);
        CREATE TABLE libreria.receipt_lines (id bigserial PRIMARY KEY, receipt_id bigint, kind text, amount numeric(18,2));');
    $nativeReceipt = DB::table('libreria.receipts')->insertGetId(['cash_session_id' => $native->id, 'status' => 'EMITIDO',
        'total' => '10.00', 'change' => '5.00', 'recipient_name' => 'Recibo V2', 'operator_name' => $user->name]);
    DB::table('libreria.receipt_payments')->insert(['receipt_id' => $nativeReceipt, 'method' => '01', 'amount' => '15.00']);
    DB::table('libreria.receipt_lines')->insert(['receipt_id' => $nativeReceipt, 'kind' => 'general', 'amount' => '10.00']);
    $nativeSale = DB::table('libreria.ventas')->insertGetId(['total' => '20.00', 'operacion' => '1', 'tipo_documento' => '01', 'estado' => 'GENERADO', 'caja_sesion_id' => $native->id]);
    DB::table('libreria.venta_dtes')->insert(['venta_id' => $nativeSale, 'numero_control' => 'DTE-01-S004P001-000000000000099',
        'codigo_generacion' => 'bbbbbbbb-1111-4111-8111-111111111111', 'pagos' => json_encode(['received' => [['method' => '01', 'amount' => '25.00']], 'change' => '5.00']),
        'cliente' => json_encode(['name' => 'Persona V2']), 'transmision_estado' => 'PENDIENTE', 'user_id' => $user->id]);
    $mirror = legacyCashReceipt(['nombre_cliente' => 'Copia ya emitida por V2', 'precio' => '99.00']);
    DB::table('public.libreria_recibo_documentos')->insert(['legacy_receipt_id' => $mirror, 'receipt_uuid' => '00000000-0000-4000-8000-000000000001', 'line_id' => 1]);
    $bridge = app(LegacyCashBridgeService::class);
    $bridge->activate('P001');
    $bridge->activate('P001');
    expect(fn () => $cash->close($user, $native->id))->toThrow(ValidationException::class);
    expect($native->fresh()->cerrado_at)->toBeNull();
    expect(DB::table('public.cajas')->where('id', 1)->value('estado_caja'))->toBeTrue();
    $page = $bridge->preparation('P001');
    expect($page['rows'])->toHaveCount(1);
    $bridge->confirm($user, 'P001', $page['version'], [$page['rows'][0]['id'] => ['session' => 1]]);
    $record = $cash->close($user, $native->id);
    expect($record->snapshot)->toMatchArray(['version' => 3, 'total' => '30.00', 'receiptTotal' => '15.00',
        'methods' => ['01' => '40.00', 'V1_CARD' => '5.00'], 'collections' => '45.00',
        'receiptCategories' => ['general' => '10.00', 'legacy' => '5.00']]);
    expect($record->snapshot['sales'][1])->toMatchArray(['source' => 'v1', 'saleId' => null, 'legacySaleId' => $sale]);
    expect($record->snapshot['origins'])->toMatchArray(['v1' => ['sales' => '10.00', 'receipts' => '5.00', 'collections' => '15.00'],
        'v2' => ['sales' => '20.00', 'receipts' => '10.00', 'collections' => '30.00']]);
    expect($record->snapshot['receipts'][1])->toMatchArray(['receiptId' => null, 'legacyReceiptId' => $receipt]);
    expect($record->snapshot['legacy']['sessions'][0]['openedAt'])->toBe('2026-11-25T07:00:00-06:00');
    expect($record->snapshot['legacy']['sessions'][0]['closedAt'])->toBe($record->snapshot['closedAt']);
    expect(DB::table('public.cajas')->where('id', 1)->value('estado_caja'))->toBeFalse();
    expect(DB::table('public.inicio_cierre_caja')->where('id', 1)->value('codigo_reapertura'))->toMatch('/^[0-9]{6}$/');
    expect($cash->close($user, $native->id)->id)->toBe($record->id);
    expect(CashReconciliation::query()->count())->toBe(1);
    $summary = app(CashSummaryService::class)->summary($record, 'recibos');
    expect($summary['methods'][1]['name'])->toBe('Tarjeta V1, sin especificar');
    $book = app(CashWorkbookService::class)->build($record);
    expect($book->getSheetByName('Arqueo')->getCell('D11')->getValue())->toBe(5.0);
    expect($book->getSheetByName('Arqueo')->getCell('D48')->getValue())->toBe(40.0);
    $writer = new Xlsx($book);
    $writer->setPreCalculateFormulas(false);
    $writer->save(Storage::disk('cash')->path('preview.xlsx'));
    $book->disconnectWorksheets();
    $reloaded = IOFactory::load(Storage::disk('cash')->path('preview.xlsx'));
    expect($reloaded->getSheetByName('Arqueo')->getCell('D11')->getValue())->toBe(5.0);
    $reloaded->disconnectWorksheets();
    $pdf = app(CashSummaryService::class)->render($record, 'ventas');
    expect($pdf)->toStartWith('%PDF-');
    Storage::disk('cash')->put('preview.pdf', $pdf);
    $frozen = $record->snapshot;
    DB::table('public.ventas')->where('id_venta', $sale)->update(['anulado' => true]);
    expect($record->fresh()->snapshot)->toBe($frozen);
    $this->withoutMiddleware([ValidateSessionWithWorkOS::class,
        EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $this->get(route('libreria.arqueos.show', $record->id))->assertOk()->assertSee('Arqueo conjunto V1 + V2')->assertSee('anulado posteriormente');
    Http::assertNothingSent();
});

test('live V1 movements use the current turn while V2 mirrors bypass V1 cash state', function () {
    prepareLegacyCashDatabase($this);
    $bridge = app(LegacyCashBridgeService::class);
    $bridge->activate('P001');
    $receipt = legacyCashReceipt();
    expect(DB::table('libreria.legacy_cash_movements')->where('legacy_id', $receipt)->where('kind', 'recibo')->value('legacy_session_id'))->toBe(1);
    DB::transaction(function () {
        DB::select("SELECT set_config('libreria.cash_source', 'v2', true)");
        $id = legacyCashReceipt(['nombre_cliente' => 'Copia V2']);
        DB::table('public.libreria_recibo_documentos')->insert(['legacy_receipt_id' => $id, 'receipt_uuid' => '00000000-0000-4000-8000-000000000001', 'line_id' => 1]);
    });
    expect(DB::table('libreria.legacy_cash_movements')->count())->toBe(1);
    DB::table('public.cajas')->where('id', 1)->update(['estado_caja' => false]);
    DB::table('public.inicio_cierre_caja')->where('id', 1)->update(['hora_cierre' => '15:00:00']);
    expect(fn () => legacyCashReceipt())->toThrow(QueryException::class, 'está cerrada');
    // P004 remains exempt even with the bridge installed.
    legacyCashReceipt(['numero_caja' => 'P004']);
    expect(DB::table('libreria.legacy_cash_movements')->count())->toBe(1);
});

test('failed V1 payment reconciliation rolls back both closes and a retry preserves credit and cash change', function () {
    $user = prepareLegacyCashDatabase($this);
    $sale = legacyCashSale();
    $credit = legacyCashSale(['numero_factura' => 'credit-uuid', 'numero_control' => 'DTE-03-S004P001-000000000000002', 'total' => '30.00', 'operacion' => '2']);
    app(LegacyCashBridgeService::class)->activate('P001');
    $cash = app(CashRegisterService::class);
    $session = $cash->open($user);
    DB::table('public.metodos_pago_venta')->where('id_venta', $sale)->update(['efectivo' => '2.00']);
    expect(fn () => $cash->close($user, $session->id))->toThrow(ValidationException::class, 'pagos');
    expect($session->fresh()->cerrado_at)->toBeNull();
    expect(DB::table('public.inicio_cierre_caja')->where('id', 1)->value('hora_cierre'))->toBeNull();
    expect(DB::table('public.cajas')->where('id', 1)->value('estado_caja'))->toBeTrue();
    DB::table('public.metodos_pago_venta')->where('id_venta', $sale)->update(['efectivo' => '12.00']);
    $record = $cash->close($user, $session->id);
    expect($record->snapshot)->toMatchArray(['total' => '40.00', 'receivable' => '30.00', 'methods' => ['01' => '10.00']]);
    expect($record->snapshot['sales'][0]['change'])->toBe('2.00');
});

test('independent V1 reopening and previously closed turns are incorporated without repeating earlier closures', function () {
    $user = prepareLegacyCashDatabase($this);
    $bridge = app(LegacyCashBridgeService::class);
    $bridge->activate('P001');
    legacyCashSale();
    // Close V1 first, then independently reopen it before closing V2.
    DB::table('public.cajas')->where('id', 1)->update(['estado_caja' => false]);
    DB::table('public.inicio_cierre_caja')->where('id', 1)->update(['hora_cierre' => '10:00:00']);
    DB::table('public.cajas')->where('id', 1)->update(['estado_caja' => true]);
    DB::table('public.inicio_cierre_caja')->insert(['id_caja' => 1, 'fecha' => '2026-11-25', 'hora_inicio' => '11:00:00']);
    legacyCashReceipt();
    DB::table('public.cajas')->where('id', 1)->update(['estado_caja' => false]);
    DB::table('public.inicio_cierre_caja')->where('id', 2)->update(['hora_cierre' => '14:00:00']);
    $cash = app(CashRegisterService::class);
    $session = $cash->open($user);
    $record = $cash->close($user, $session->id);
    expect($record->snapshot['legacy']['sessions'])->toHaveCount(2);
    expect($record->snapshot['collections'])->toBe('15.00');
    $second = $cash->open($user, $session->fresh()->codigo_reapertura, 'Segunda sesión de prueba');
    expect(DB::table('public.cajas')->where('id', 1)->value('estado_caja'))->toBeFalse();
    $next = $cash->close($user, $second->id);
    expect($next->snapshot['collections'])->toBe('0.00');
    expect($next->snapshot['legacy']['sessions'])->toBe([]);
});

test('initial receipt confirmation rejects stale values and unauthorized users and requires proof for missing amounts', function () {
    $user = prepareLegacyCashDatabase($this);
    $id = legacyCashReceipt(['precio' => null, 'tarjeta' => null]);
    $bridge = app(LegacyCashBridgeService::class);
    $bridge->activate('P001');
    $page = $bridge->preparation('P001');
    $movement = $page['rows'][0]['id'];
    $user->roles()->sync([Role::query()->where('code', 'libreria')->sole()->id]);
    expect(fn () => $bridge->confirm($user, 'P001', $page['version'], [$movement => ['session' => 1]]))->toThrow(AuthorizationException::class);
    $user->roles()->sync([Role::query()->where('code', 'admin')->sole()->id]);
    expect(fn () => $bridge->confirm($user, 'P001', $page['version'], [$movement => ['session' => 1, 'amount' => '7.00']]))->toThrow(ValidationException::class);
    DB::table('public.recibos')->where('id', $id)->update(['observacion' => 'Cambio externo']);
    expect(fn () => $bridge->confirm($user, 'P001', $page['version'], [$movement => ['session' => 1, 'amount' => '7.00', 'reason' => 'Comprobante verificado']]))->toThrow(ValidationException::class, 'lista cambió');
    $page = $bridge->preparation('P001');
    $bridge->confirm($user, 'P001', $page['version'], [$movement => ['session' => 1, 'amount' => '7.00', 'method' => 'V1_CARD', 'reason' => 'Comprobante verificado']]);
    expect($bridge->preparation('P001')['pending']->total())->toBe(0);
    expect(DB::table('libreria.legacy_cash_movements')->where('id', $movement)->value('confirmed_amount'))->toBe('7.00');
    $bridge->deactivate('P001');
    expect($bridge->active('P001'))->toBeFalse();
    expect(DB::table('libreria.legacy_cash_movements')->count())->toBe(1);
});

test('V1 writers and closure contend for the same register lock', function () {
    prepareLegacyCashDatabase($this);
    app(LegacyCashBridgeService::class)->activate('P001');
    config(['database.connections.legacy_bridge_competing' => config('database.connections.legacy_bridge_test')]);
    $other = DB::connection('legacy_bridge_competing');
    $other->statement("SET lock_timeout = '100ms'");
    DB::beginTransaction();
    try {
        DB::table('public.cajas')->where('id', 1)->lockForUpdate()->first();
        expect(fn () => $other->table('public.recibos')->insert(['nombre_cliente' => 'Concurrente', 'numero_caja' => 'P001', 'precio' => '1.00', 'tarjeta' => false]))
            ->toThrow(QueryException::class, 'lock timeout');
    } finally {
        DB::rollBack();
    }
    legacyCashReceipt();
    expect(DB::table('libreria.legacy_cash_movements')->count())->toBe(1);
});

test('preparation screen enforces review permission and accepts a checked initial selection', function () {
    $user = prepareLegacyCashDatabase($this);
    $this->withoutMiddleware([ValidateSessionWithWorkOS::class,
        EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    legacyCashReceipt();
    $this->artisan('cash:legacy-bridge activate --register=P001')->assertSuccessful();
    $page = app(LegacyCashBridgeService::class)->preparation('P001');
    $this->get(route('libreria.cajas.legacy.show', 'P001'))->assertOk()->assertSee('Turno confirmado')->assertSee('1 pendientes');
    $user->roles()->sync([Role::query()->where('code', 'libreria')->sole()->id]);
    $this->get(route('libreria.cajas.legacy.show', 'P001'))->assertForbidden();
    $user->roles()->sync([Role::query()->where('code', 'contador')->sole()->id]);
    $this->post(route('libreria.cajas.legacy.confirm', 'P001'), ['version' => $page['version'], 'confirmed' => '1',
        'assignments' => [$page['rows'][0]['id'] => ['session' => 1]]])->assertRedirect();
    expect(app(LegacyCashBridgeService::class)->preparation('P001')['pending']->total())->toBe(0);
    $this->artisan('cash:legacy-bridge status --register=P001')->assertSuccessful();
    $this->artisan('cash:legacy-bridge deactivate --register=P001')->assertSuccessful();
    expect(app(LegacyCashBridgeService::class)->active('P001'))->toBeFalse();
});

test('duplicate V1 boxes prevent activation and the unsupported register remains exempt', function () {
    prepareLegacyCashDatabase($this);
    DB::table('public.cajas')->insert(['codigo_caja' => 'P001', 'estado_caja' => false]);
    expect(fn () => app(LegacyCashBridgeService::class)->activate('P001'))->toThrow(ValidationException::class, 'única caja');
    expect(app(LegacyCashBridgeService::class)->active('P001'))->toBeFalse();
    $this->artisan('cash:legacy-bridge activate --register=P004')->assertFailed();
});

test('activation and closure compare native UUIDs with legacy text without counting copied invoices twice', function () {
    $user = prepareLegacyCashDatabase($this);
    $cash = app(CashRegisterService::class);
    $session = $cash->open($user);
    $uuid = 'abcdefab-1234-4567-8123-123456789abc';
    $number = 'DTE-01-S004P001-000000000000099';
    $nativeId = DB::table('libreria.ventas')->insertGetId(['total' => '10.00', 'operacion' => '1', 'tipo_documento' => '01',
        'estado' => 'GENERADO', 'caja_sesion_id' => $session->id]);
    DB::table('libreria.venta_dtes')->insert(['venta_id' => $nativeId, 'numero_control' => $number, 'codigo_generacion' => $uuid,
        'pagos' => json_encode(['received' => [['method' => '01', 'amount' => '10.00']], 'change' => '0.00']),
        'cliente' => json_encode(['name' => 'Cliente V2']), 'transmision_estado' => 'PENDIENTE', 'user_id' => $user->id]);
    legacyCashSale(['numero_factura' => strtoupper($uuid), 'numero_control' => $number]);
    // A historical non-UUID value must not be cast to PostgreSQL uuid either.
    legacyCashSale(['numero_factura' => 'referencia-antigua', 'numero_control' => 'DTE-01-S004P001-000000000000001']);
    $bridge = app(LegacyCashBridgeService::class);
    $bridge->activate('P001');
    expect(DB::table('libreria.legacy_cash_movements')->where('kind', 'venta')->count())->toBe(1);
    // Also exercise the close query on a copy written after activation.
    legacyCashSale(['numero_factura' => strtoupper($uuid), 'numero_control' => $number]);
    $record = $cash->close($user, $session->id);
    expect($record->snapshot)->toMatchArray(['total' => '20.00', 'collections' => '20.00', 'methods' => ['01' => '20.00']]);
    expect($record->snapshot['sales'])->toHaveCount(2);
    expect($bridge->active('P001'))->toBeTrue();
});
