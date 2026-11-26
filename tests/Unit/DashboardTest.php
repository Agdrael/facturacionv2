<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Models\Libreria\CashSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Dashboard\DashboardMetricsService;
use App\Services\Libreria\LegacyCashBridgeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/LegacyCashDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    $this->withoutVite();
});

function prepareDashboardDatabase(TestCase $test): User
{
    $user = prepareLegacyCashDatabase($test);
    config(['puntoventa' => ['127.0.0.1' => 'P001']]);
    DB::unprepared('ALTER TABLE libreria.ventas ADD COLUMN caja text, ADD COLUMN fecha date, ADD COLUMN created_at timestamptz;
        CREATE TABLE libreria.receipts (id bigserial PRIMARY KEY, register text, created_at timestamptz, total numeric(18,2),
            change numeric(18,2), status text, operator_name text);
        CREATE TABLE libreria.receipt_payments (id bigserial PRIMARY KEY, receipt_id bigint, method text, amount numeric(18,2));');

    return $user;
}

afterEach(function () {
    if (isset($this->legacyDatabase, $this->legacyAdmin) && preg_match('/^codex_cash_bridge_[a-f0-9]{16}$/', $this->legacyDatabase)) {
        DB::purge('legacy_bridge_test');
        DB::purge('legacy_bridge_competing');
        DB::purge('dtesantacecilia');
        $this->legacyAdmin->exec('DROP DATABASE '.$this->legacyDatabase.' WITH (FORCE)');
    }
});

/** @param array<string, mixed> $changes
 * @param list<array{method: string, amount: string}> $payments */
function dashboardSale(array $changes = [], array $payments = [['method' => '01', 'amount' => '10.00']], string $change = '0.00', array $journal = []): int
{
    $id = DB::table('libreria.ventas')->insertGetId(array_replace([
        'caja' => 'P001', 'fecha' => '2026-11-25', 'created_at' => '2026-11-25 10:00:00-06',
        'total' => '10.00', 'operacion' => '1', 'tipo_documento' => '01', 'estado' => 'GENERADO',
    ], $changes));
    DB::table('libreria.venta_dtes')->insert(array_replace([
        'venta_id' => $id, 'numero_control' => 'DTE-01-S004P001-'.str_pad((string) $id, 15, '0', STR_PAD_LEFT),
        'codigo_generacion' => (string) Str::uuid(), 'pagos' => json_encode(['received' => $payments, 'change' => $change]),
        'cliente' => '{}', 'transmision_estado' => 'ACEPTADO', 'user_id' => auth()->id(),
    ], $journal));

    return $id;
}

/** @param array<string, mixed> $changes */
function dashboardReceipt(array $changes = [], array $payments = [['method' => '01', 'amount' => '10.00']]): int
{
    $id = DB::table('libreria.receipts')->insertGetId(array_replace(['register' => 'P001', 'created_at' => '2026-11-25 12:00:00-06',
        'total' => '10.00', 'change' => '0.00', 'status' => 'EMITIDO', 'operator_name' => 'Otro operador'], $changes));
    foreach ($payments as $payment) {
        DB::table('libreria.receipt_payments')->insert(['receipt_id' => $id, ...$payment]);
    }

    return $id;
}

test('dashboard separates DTE credit receipts and net cash across operators and sessions without writing', function () {
    $user = prepareDashboardDatabase($this);
    $register = DB::table('libreria.cajas')->where('codigo', 'P001')->value('id');
    $closed = CashSession::factory()->closed()->create(['caja_id' => $register, 'abierto_at' => '2026-11-25 07:00:00-06']);
    $open = CashSession::factory()->create(['caja_id' => $register, 'abierto_at' => '2026-11-25 11:00:00-06']);
    dashboardSale(['total' => '25.00', 'caja_sesion_id' => $closed->id], [
        ['method' => '01', 'amount' => '20.00'], ['method' => '03', 'amount' => '10.00'],
    ], '5.00');
    $operator = User::factory()->create();
    dashboardSale(['total' => '100.00', 'operacion' => '2', 'tipo_documento' => '03', 'caja_sesion_id' => $open->id],
        [['method' => '01', 'amount' => '15.00']], '0.00', ['user_id' => $operator->id, 'transmision_estado' => 'PENDIENTE']);
    dashboardReceipt(['total' => '20.00', 'change' => '5.00', 'status' => 'PENDIENTE_APLICACION'], [['method' => '01', 'amount' => '25.00']]);
    dashboardSale(['estado' => 'ANULADO', 'total' => '99.00']);
    dashboardReceipt(['status' => 'ANULADO', 'total' => '30.00']);
    dashboardSale(['fecha' => '2026-11-24']);
    dashboardReceipt(['created_at' => '2026-11-25 05:59:59+00']);
    dashboardReceipt(['created_at' => '2026-11-26 06:00:00+00']);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $data = app(DashboardMetricsService::class)->query($user, '2026-11-25', '2026-11-25');
    expect($data['summary'])->toMatchArray(['sales' => '125.00', 'salesCount' => 2, 'saleCollected' => '40.00',
        'receipts' => '20.00', 'receiptCount' => 1, 'collections' => '60.00', 'cash' => '50.00', 'credit' => '85.00',
        'pendingDte' => 1, 'pendingReceipt' => 1, 'annulledCount' => 2, 'annulledAmount' => '129.00']);
    expect($data['summary']['methods']['01'])->toMatchArray(['sales' => '30.00', 'receipts' => '20.00', 'total' => '50.00']);
    expect($data['summary']['documents']['03'])->toMatchArray(['count' => 1, 'amount' => '100.00']);
    expect($data['days'][0]['collections'])->toBe($data['summary']['collections']);
    expect($data['boxes']['P001']['collections'])->toBe('60.00');
    expect($data['openV2'])->toBe(1);
    expect(collect($queries)->filter(fn ($sql) => ! preg_match('/^\s*(select|pragma)/i', $sql)))->toBeEmpty();
    expect($open->fresh()->cerrado_at)->toBeNull();
    Http::assertNothingSent();
});

test('dashboard honors initial confirmations exclusions closed V1 turns and V2 mirrors', function () {
    $user = prepareDashboardDatabase($this);
    DB::table('public.inicio_cierre_caja')->where('id', 1)->update(['fecha' => '2026-11-24']);
    legacyCashSale(['fecha_sistema' => '2026-11-24', 'numero_factura' => 'previous-day', 'numero_control' => 'DTE-01-S004P001-000000000000098']);
    $receipt = legacyCashReceipt(['precio' => null, 'tarjeta' => null]);
    $excluded = legacyCashReceipt(['precio' => '500.00']);
    $bridge = app(LegacyCashBridgeService::class);
    $bridge->activate('P001');
    $metrics = app(DashboardMetricsService::class);
    expect($metrics->query($user, '2026-11-25', '2026-11-25')['summary']['issues'])->toBe(2);
    $page = $bridge->preparation('P001');
    $assignments = [];
    foreach ($page['rows'] as $row) {
        $assignments[$row['id']] = $row['legacyId'] === $receipt
            ? ['session' => 1, 'amount' => '12.34', 'method' => 'V1_CARD', 'reason' => 'Comprobante verificado']
            : ['session' => 0, 'reason' => 'Fuera del corte confirmado'];
    }
    $bridge->confirm($user, 'P001', $page['version'], $assignments);
    legacyCashSale();
    legacyCashSale(['numero_factura' => 'credit-uuid', 'numero_control' => 'DTE-03-S004P001-000000000000002', 'total' => '30.00', 'operacion' => '2']);
    $uuid = 'AABBCCDD-1111-4111-8111-111111111111';
    legacyCashSale(['numero_factura' => ' '.$uuid.' ', 'numero_control' => 'DTE-01-S004P001-000000000000003']);
    dashboardSale([], [['method' => '01', 'amount' => '10.00']], '0.00', ['codigo_generacion' => strtolower($uuid), 'numero_control' => 'DTE-01-S004P001-000000000000099']);
    DB::transaction(function () {
        DB::select("SELECT set_config('libreria.cash_source', 'v2', true)");
        $mirror = legacyCashReceipt(['precio' => '999.00']);
        DB::table('public.libreria_recibo_documentos')->insert(['legacy_receipt_id' => $mirror, 'receipt_uuid' => (string) Str::uuid(), 'line_id' => 1]);
    });
    DB::table('libreria.legacy_cash_sessions')->where('legacy_id', 1)->update(['reconciliation_id' => 88, 'closed_at' => now()]);
    $data = $metrics->query($user, '2026-11-25', '2026-11-25', 'P001');
    expect($data['summary'])->toMatchArray(['sales' => '50.00', 'salesCount' => 3, 'credit' => '30.00',
        'receiptCount' => 1, 'receipts' => '12.34', 'collections' => '32.34', 'issues' => 0]);
    expect($data['summary']['methods']['V1_CARD']['total'])->toBe('12.34');
    expect($data['summary']['origins']['v1']['collections'])->toBe('22.34');
    expect($metrics->query($user, '2026-11-24', '2026-11-25', 'P001')['summary']['collections'])->toBe('42.34');
    expect(DB::table('public.recibos')->where('id', $excluded)->value('precio'))->toBe('500.000000');
    DB::table('public.ventas')->where('numero_factura', 'legacy-uuid-1')->update(['anulado' => true]);
    expect($metrics->query($user, '2026-11-25', '2026-11-25', 'P001')['summary'])->toMatchArray(['annulledCount' => 1, 'collections' => '22.34']);
});

test('P004 starts at first global activation and ambiguous data remain unknown', function () {
    $user = prepareDashboardDatabase($this);
    app(LegacyCashBridgeService::class)->activate('P001');
    legacyCashSale(['numero_control' => 'DTE-01-S004P004-000000000000001', 'numero_factura' => 'before', 'fecha_sistema' => '2026-11-24']);
    legacyCashSale(['numero_control' => 'DTE-01-S004P004-000000000000002', 'numero_factura' => 'valid']);
    legacyCashReceipt(['numero_caja' => 'P004', 'precio' => '7.50']);
    legacyCashReceipt(['numero_caja' => 'P004', 'precio' => null]);
    $metrics = app(DashboardMetricsService::class);
    $data = $metrics->query($user, '2026-11-24', '2026-11-25', 'P004');
    expect($data['summary'])->toMatchArray(['sales' => '10.00', 'receipts' => '7.50', 'collections' => '17.50', 'issues' => 1]);
    expect($data['coverage']['P004'])->toMatchArray(['from' => '2026-11-25', 'partial' => true]);
    expect($data['boxes']['P004']['state'])->toMatchArray(['v1' => 'Sin control de apertura', 'v2' => 'Sin control de apertura']);
    expect($data['openV1'])->toBe(0)->and($data['openV2'])->toBe(0);
    legacyCashSale(['numero_control' => 'DTE-01-S004P004-000000000000003', 'numero_factura' => ' VALID ']);
    $duplicate = $metrics->query($user, '2026-11-25', '2026-11-25', 'P004');
    expect($duplicate['summary'])->toMatchArray(['salesCount' => 0, 'issues' => 3]);
    expect(collect($duplicate['recent'])->where('kind', 'sale')->pluck('amount')->all())->toBe([null, null]);
    dashboardSale(['caja' => 'P004'], []);
    $invalid = $metrics->query($user, '2026-11-25', '2026-11-25', 'P004');
    expect($invalid['summary']['origins']['v2'])->toMatchArray(['count' => 0, 'issues' => 1]);
});

test('cashiers cannot select another box or period and multiple roles select executive only when authorized', function () {
    $user = prepareDashboardDatabase($this);
    $user->roles()->sync(Role::where('code', 'libreria')->pluck('id'));
    dashboardSale();
    dashboardSale(['caja' => 'P002', 'total' => '123.45'], [['method' => '01', 'amount' => '123.45']]);
    $metrics = app(DashboardMetricsService::class);
    $data = $metrics->query($user, '2026-11-25', '2026-11-25');
    expect($data['mode'])->toBe('cashier')->and(array_keys($data['boxes']))->toBe(['P001'])->and($data['summary']['collections'])->toBe('10.00');
    expect(fn () => $metrics->query($user, '2026-11-25', '2026-11-25', 'P002'))->toThrow(HttpException::class);
    expect(fn () => $metrics->query($user, '2026-11-24', '2026-11-25'))->toThrow(HttpException::class);
    $contador = Role::where('code', 'contador')->sole();
    $user->roles()->attach($contador);
    expect($metrics->query($user, '2026-11-25', '2026-11-25')['summary']['collections'])->toBe('133.45');
    $contador->permissions()->detach(Permission::where('code', 'libreria.cajas.ver_todas')->value('id'));
    expect($metrics->mode($user))->toBe('cashier');
});

test('live dashboard rechecks role permission and IP on every update and protects calculated values', function () {
    $user = prepareDashboardDatabase($this);
    $user->name = '<script>alert("operador")</script>';
    DB::table('users')->where('id', $user->id)->update(['name' => $user->name]);
    dashboardSale();
    dashboardSale(['caja' => 'P002', 'total' => '123.45'], [['method' => '01', 'amount' => '123.45']]);
    $component = Livewire::test('pages::dashboard')->assertSee('Resumen ejecutivo')->assertSet('data.summary.collections', '133.45');
    $user->roles()->sync(Role::where('code', 'libreria')->pluck('id'));
    $component->call('refreshData')->assertSee('Mi caja')->assertDontSee('Resumen ejecutivo')->assertSee($user->name)->assertDontSee($user->name, false)->assertSet('data.summary.collections', '10.00')
        ->set('register', 'P002')->call('refreshData')->assertSet('data.register', 'P001');
    config(['puntoventa' => ['127.0.0.1' => 'P002']]);
    $component->call('refreshData')->assertSet('data.register', 'P002')->assertSet('data.summary.collections', '123.45');
    Role::where('code', 'libreria')->sole()->permissions()->detach(Permission::where('code', 'libreria.cajas.ver')->value('id'));
    $component->call('refreshData')->assertSet('data.mode', 'welcome')->assertDontSee('123.45')->assertDontSee('Total cobrado');
    expect(fn () => $component->set('data.summary.collections', '999999'))->toThrow(CannotUpdateLockedPropertyException::class);
    Http::assertNothingSent();
});

test('dashboard route renders SVG charts validates ranges and retains safe last query on invalid input', function () {
    $user = prepareDashboardDatabase($this);
    $this->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class]);
    dashboardSale();
    $this->get(route('dashboard'))->assertOk()->assertSee('Resumen ejecutivo')->assertSee('Cobros por caja')->assertSee('<svg', false);
    $component = Livewire::test('pages::dashboard');
    $component->call('period', 7)->assertSet('data.from', '2026-11-19');
    $component->call('period', 30)->assertSet('data.from', '2026-10-27');
    $component->set('to', '2026-11-26')->call('refreshData')->assertHasErrors('to')->assertSet('data.to', '2026-11-25');
    $component->set('from', '2026-11-25')->set('to', '2026-11-24')->call('refreshData')->assertHasErrors('from');
    $component->set('from', '2026-11-25')->set('to', '2026-11-25')->set('register', "P001' OR 1=1 --")->call('refreshData')->assertHasErrors('register');
    $this->app['auth']->forgetGuards();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
