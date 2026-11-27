<?php

use App\Models\User;
use App\Services\Libreria\ReceiptSourceService;
use Carbon\CarbonImmutable;
use Database\Seeders\CashControlSeeder;
use Database\Seeders\ReceiptControlSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/DteDatabase.php';

function prepareReceipts(TestCase $test, string $point = 'P004'): User
{
    $sqlite = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false];
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => $sqlite, 'database.connections.dtesantacecilia' => $sqlite,
        'database.connections.receipt_legacy' => $sqlite, 'database.connections.plataforma' => $sqlite,
        'receipts.enabled' => true, 'receipts.legacy_connection' => 'receipt_legacy', 'receipts.platform_connection' => 'plataforma',
        'employee-access.allowed_domain' => 'example.test', 'services.factec.transmission_enabled' => false, 'services.factec.environment' => '01']);
    foreach (['sqlite', 'dtesantacecilia', 'receipt_legacy', 'plataforma'] as $connection) {
        DB::purge($connection);
    }
    $test->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/El_Salvador'));
    Http::preventStrayRequests();
    prepareDteGeneration($test);
    (require __DIR__.'/../../database/migrations/2026_11_18_135412_create_cash_control_tables.php')->up();
    (require __DIR__.'/../../database/migrations/2026_11_20_161442_create_receipt_control_tables.php')->up();
    foreach (['receipt_legacy', 'plataforma'] as $connection) {
        DB::connection($connection)->statement("ATTACH DATABASE ':memory:' AS public");
    }
    $legacy = DB::connection('receipt_legacy');
    $definitions = [
        'clientes' => 'id INTEGER PRIMARY KEY, nombre TEXT, dui TEXT, nit TEXT, cta_cte TEXT',
        'usuarios' => 'id_usuario INTEGER PRIMARY KEY, correo TEXT',
        'ventas' => 'id_venta INTEGER PRIMARY KEY, numero_factura TEXT, numero_control TEXT, total NUMERIC, operacion TEXT, anulado BOOLEAN, n_carnet TEXT',
        'productos_vendidos' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, id_ventas INTEGER, nombre_producto TEXT',
        'cuentas_cargo_reporte' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, llave TEXT',
        'recibos' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, uuid_asignado TEXT, nombre_cliente TEXT, fecha TEXT, fecha_sistema TEXT, cuenta_cargo TEXT, codigo_recibo TEXT, cuenta_abono TEXT, numero_carnet TEXT, numero_caja TEXT, observacion TEXT, precio NUMERIC, tarjeta BOOLEAN, anulado BOOLEAN, cxc BOOLEAN, cxc_id INTEGER, id_detalle_pago INTEGER',
        'recibos_anulados' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, id_recibo INTEGER, cuenta_cargo TEXT, id_usuario INTEGER, fecha TEXT',
        'saldo_cargos' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, id_cliente INTEGER, saldo NUMERIC, saldo_pendiente NUMERIC, fecha TEXT, uuid_venta TEXT, anulado BOOLEAN',
        'saldo_abonos' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, id_cliente INTEGER, saldo NUMERIC',
        'saldo_clientes' => 'id_cliente INTEGER PRIMARY KEY, saldo NUMERIC, movimiento TEXT',
    ];
    foreach ($definitions as $table => $columns) {
        $legacy->statement('CREATE TABLE public.'.$table.' ('.$columns.')');
    }
    $platform = DB::connection('plataforma');
    $definitions = [
        'students' => 'student_id INTEGER PRIMARY KEY, first_name TEXT, middle_name TEXT, last_name TEXT',
        'student_enrollment' => 'student_id INTEGER, syear INTEGER, grade_id INTEGER, especialidad TEXT, seccion TEXT',
        'school_gradelevels' => 'id INTEGER PRIMARY KEY, title TEXT',
        'cxc' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, student_id TEXT, monto NUMERIC, item_pago TEXT, fecha_transaccion TEXT, custom_275 TEXT, custom_277 TEXT, facturado BOOLEAN, cuenta_contable TEXT, recibo BOOLEAN, parciales NUMERIC, syear INTEGER, UNIQUE(student_id,item_pago,syear)',
        'revisiones' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, monto NUMERIC, item_pago NUMERIC, custom_275 TEXT, custom_277 TEXT, estado TEXT, facturado BOOLEAN, numero_factura TEXT, syear INTEGER',
        'saldo_pagos' => 'id_saldo INTEGER PRIMARY KEY, student_id TEXT, mes INTEGER, syear INTEGER, cuota NUMERIC, pago NUMERIC, saldo NUMERIC, cuota_beca NUMERIC, pago_beca NUMERIC, saldo_beca NUMERIC, cuota_bienhechor NUMERIC, pago_bienhechor NUMERIC, saldo_bienhechor NUMERIC, fecha_actualizado TEXT, habilitado INTEGER',
        'detalle_pagos' => 'id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, student_id TEXT, mes_pagado INTEGER, fecha TEXT, monto NUMERIC, lugar TEXT, syear INTEGER, uid_dte TEXT, fecha_emision_dte TEXT, numero_dte TEXT, facturado BOOLEAN, pago_beca BOOLEAN, pago_bienhechor BOOLEAN, estado TEXT, fecha_creado TEXT, updated_at TEXT',
    ];
    foreach ($definitions as $table => $columns) {
        $platform->statement('CREATE TABLE public.'.$table.' ('.$columns.')');
    }
    (require __DIR__.'/../../database/migrations/2026_11_20_161443_create_receipt_external_operation_tables.php')->up();
    DB::statement('CREATE TABLE public.catalogo_recibos (id INTEGER PRIMARY KEY, nombre_recibo TEXT, cuenta_abono TEXT, precio INTEGER)');
    DB::table('public.catalogo_recibos')->insert([
        ['id' => 1, 'nombre_recibo' => 'Constancia', 'cuenta_abono' => '5101', 'precio' => 10],
        ['id' => 2, 'nombre_recibo' => 'FOTOGRAFIAS', 'cuenta_abono' => '5101', 'precio' => 11],
        ['id' => 3, 'nombre_recibo' => 'PAGO EN ANTICIPO', 'cuenta_abono' => '110201010101', 'precio' => 0],
    ]);
    DB::statement('ALTER TABLE public.catalogo_contabilidad ADD COLUMN descripcion TEXT');
    DB::statement('ALTER TABLE public.catalogo_contabilidad ADD COLUMN terminal TEXT');
    DB::table('public.catalogo_contabilidad')->insert([
        ['id_catalogo' => 3, 'cuenta' => '110201010101', 'descripcion' => 'CXC primer grupo', 'terminal' => 'T'],
        ['id_catalogo' => 4, 'cuenta' => '110201010102', 'descripcion' => 'CXC segundo grupo', 'terminal' => 'T'],
        ['id_catalogo' => 5, 'cuenta' => '110204010122', 'descripcion' => 'CXC genérica para recibos', 'terminal' => 'T'],
    ]);
    foreach (['dui', 'nit', 'email'] as $column) {
        DB::statement('ALTER TABLE libreria.clientes ADD COLUMN '.$column.' TEXT');
    }
    DB::table('libreria.clientes')->where('id', 1)->update(['nombre' => 'Responsable de prueba', 'dui' => '01234567-8', 'credito' => true, 'cuenta_contable' => '110201010101']);
    $legacy->table('public.clientes')->insert(['id' => 8, 'nombre' => 'Responsable', 'dui' => '01234567-8', 'cta_cte' => '110201010101']);
    DB::connection('dtesantacecilia')->statement('CREATE TABLE public.empleados (empleado_id TEXT PRIMARY KEY, codigo TEXT, correo TEXT)');
    $user = auth()->user();
    DB::connection('dtesantacecilia')->table('public.empleados')->insert(['empleado_id' => 'E7', 'codigo' => 'EMP7', 'correo' => $user->email]);
    $user->setAttribute('puntoventa', $point);
    $test->seed(CashControlSeeder::class);
    $test->seed(ReceiptControlSeeder::class);
    $platform->table('public.students')->insert(['student_id' => 501, 'first_name' => 'Alumno', 'middle_name' => '', 'last_name' => 'Uno']);
    $platform->table('public.school_gradelevels')->insert(['id' => 1, 'title' => '1°']);
    $platform->table('public.student_enrollment')->insert(['student_id' => 501, 'syear' => 2025, 'grade_id' => 1, 'especialidad' => '']);
    $legacy->table('public.cuentas_cargo_reporte')->insert(['nombre' => '1° cxc', 'llave' => '110201010101']);

    return $user;
}

function receiptPayload(array $recipients = []): array
{
    $entry = $recipients[0] ?? ['type' => 'manual', 'name' => 'Destinatario de prueba', 'lines' => [['conceptId' => 1, 'amount' => '10.00']], 'payments' => [['method' => '01', 'amount' => '12.00']]];
    $line = $entry['lines'][0];
    $input = ['requestKey' => (string) Str::uuid(), 'mode' => 'catalog', 'recipient' => ['name' => $entry['name'] ?? 'Responsable de prueba'],
        'amount' => $line['amount'], 'payments' => $entry['payments']];
    if (isset($line['obligationKey'])) {
        if (preg_match('/^academic:([0-9]+):(normal|beca|bienhechor)$/', $line['obligationKey'], $match)) {
            $input += ['sourceId' => (int) $match[1], 'funding' => $match[2], 'account' => $line['account'] ?? '110201010101'];
            $input['mode'] = 'academic_cxc';
        } else {
            $input['mode'] = 'client_cxc';
            $input['sourceKey'] = $line['obligationKey'];
        }
    } else {
        $input['conceptId'] = $line['conceptId'];
        if ($entry['type'] === 'cliente') {
            $input['recipient']['clientId'] = (int) $entry['id'];
        }
        if ($entry['type'] === 'alumno') {
            $input['studentId'] = $entry['id'];
            $input['year'] = $line['year'];
            $input['funding'] = $line['funding'] ?? 'normal';
        }
    }

    return $input;
}

function receiptAcademicDebt(?string $remaining = null, int $year = 2025): array
{
    $uuid = (string) Str::uuid();
    DB::connection('receipt_legacy')->table('public.ventas')->insert(['id_venta' => $year, 'numero_factura' => $uuid,
        'numero_control' => 'DTE-LEGACY-'.$year, 'total' => '50.00', 'operacion' => '2', 'anulado' => false, 'n_carnet' => '501']);
    $platform = DB::connection('plataforma');
    $id = $platform->table('public.cxc')->insertGetId(['student_id' => '501', 'monto' => '50.00', 'parciales' => $remaining, 'item_pago' => '2',
        'syear' => $year, 'custom_275' => 'Responsable', 'custom_277' => 'responsable@example.test', 'facturado' => true, 'cuenta_contable' => '110201010101', 'recibo' => $remaining === '0.00']);
    $platform->table('public.saldo_pagos')->insert(['id_saldo' => $year, 'student_id' => '501', 'mes' => 2, 'syear' => $year,
        'cuota' => 50, 'pago' => 50 - (float) ($remaining ?? 50), 'saldo' => $remaining ?? '50.00', 'pago_beca' => 0, 'saldo_beca' => 0, 'pago_bienhechor' => 0, 'saldo_bienhechor' => 0]);

    return ['id' => $id, 'uuid' => $uuid, 'year' => $year];
}

function receiptLinkAcademic(array $debt): string
{
    app(ReceiptSourceService::class)->linkAcademic($debt['id'], 'normal', 'legacy', $debt['uuid'], auth()->id(), 'Verificada factura de origen');

    return 'academic:'.$debt['id'].':normal';
}

function receiptAcademicPayload(string $key, string $amount = '20.00'): array
{
    return receiptPayload([['type' => 'alumno', 'id' => '501', 'lines' => [['obligationKey' => $key, 'amount' => $amount]],
        'payments' => [['method' => '01', 'amount' => $amount]]]]);
}
