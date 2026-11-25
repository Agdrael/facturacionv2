<?php

use App\Models\Libreria\CashRegister;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\AccessControlService;
use Carbon\CarbonImmutable;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

function prepareLegacyCashDatabase(TestCase $test): User
{
    if (! is_file(base_path('.env'))) {
        $test->markTestSkipped('Las pruebas PG requieren el entorno local de desarrollo.');
    }
    $environment = Dotenv::parse(file_get_contents(base_path('.env')));
    // Never create or replace tables in the configured application database.
    if (! preg_match('/(?:prueba|test|dev)/i', $environment['DB_DATABASE'] ?? '')) {
        $test->markTestSkipped('Las pruebas PG solo se ejecutan contra un servidor de desarrollo identificado por DB_DATABASE.');
    }
    $test->legacyDatabase = 'codex_cash_bridge_'.bin2hex(random_bytes(8));
    $test->legacyAdmin = new PDO('pgsql:host='.$environment['DB_HOST'].';port='.($environment['DB_PORT'] ?? '5432').';dbname=postgres',
        $environment['DB_USERNAME'], $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $test->legacyAdmin->exec('CREATE DATABASE '.$test->legacyDatabase);
    config(['database.default' => 'legacy_bridge_test', 'database.connections.legacy_bridge_test' => [
        'driver' => 'pgsql', 'host' => $environment['DB_HOST'], 'port' => $environment['DB_PORT'] ?? '5432',
        'database' => $test->legacyDatabase, 'username' => $environment['DB_USERNAME'], 'password' => $environment['DB_PASSWORD'],
        'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'timezone' => 'America/El_Salvador', 'sslmode' => 'prefer',
    ], 'receipts.enabled' => false, 'receipts.legacy_connection' => 'legacy_bridge_test', 'employee-access.allowed_domain' => 'example.test',
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('legacy_bridge_test');
    DB::purge('dtesantacecilia');
    $test->travelTo(CarbonImmutable::parse('2026-11-25 15:00:00', 'America/El_Salvador'));
    Http::preventStrayRequests();
    Storage::fake('cash');
    DB::statement('CREATE SCHEMA libreria');
    (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
    (require database_path('migrations/2026_11_17_212602_create_access_control_tables.php'))->up();
    DB::unprepared('
        CREATE TABLE public.cajas (id serial PRIMARY KEY, codigo_caja varchar(4), estado_caja boolean DEFAULT false);
        CREATE TABLE public.inicio_cierre_caja (id bigserial PRIMARY KEY, id_caja integer, fecha date DEFAULT CURRENT_DATE,
            hora_inicio time DEFAULT CURRENT_TIME, hora_cierre time, codigo_reapertura text, motivo_reapertura text);
        CREATE TABLE public.clientes (id serial PRIMARY KEY, nombre text);
        CREATE TABLE public.ventas (id_venta serial PRIMARY KEY, numero_factura text, numero_control text, fecha_sistema date,
            hora text, anulado boolean, operacion text DEFAULT '."'1'".', total numeric(26,6), id_cliente integer, id_usuario integer, sello_recepcion text);
        CREATE TABLE public.metodos_pago_venta (id serial PRIMARY KEY, id_venta integer, forma_pago text,
            efectivo numeric(18,8), remesa numeric(18,8), tarjetac numeric(18,8), tarjetad numeric(18,8), linea numeric(18,8), qr numeric(18,8), bitcoin numeric(18,8), cheque numeric(18,8));
        CREATE TABLE public.recibos (id serial PRIMARY KEY, uuid_asignado text, nombre_cliente text, fecha_sistema date DEFAULT CURRENT_DATE,
            numero_caja text, precio numeric(26,6), tarjeta boolean, anulado boolean, codigo_recibo text, cuenta_abono text, cuenta_cargo text, observacion text);
        CREATE TABLE public.libreria_recibo_documentos (legacy_receipt_id bigint PRIMARY KEY, receipt_uuid uuid, line_id bigint);
        CREATE TABLE libreria.ventas (id bigserial PRIMARY KEY, total numeric(18,2), operacion text, tipo_documento text, estado text);
        CREATE TABLE libreria.venta_dtes (id bigserial PRIMARY KEY, venta_id bigint, numero_control text, codigo_generacion text,
            pagos text, cliente text, transmision_estado text, user_id bigint);
        CREATE TABLE libreria.venta_invalidaciones (id bigserial PRIMARY KEY, venta_dte_id bigint, aplicada_at timestamptz);
    ');
    (require database_path('migrations/2026_11_18_135412_create_cash_control_tables.php'))->up();
    (require database_path('migrations/2026_11_25_142240_create_legacy_cash_bridge_tables.php'))->up();
    app(AccessControlService::class)->synchronize();
    foreach (['P001', 'P002', 'P003'] as $code) {
        CashRegister::factory()->create(['codigo' => $code]);
        DB::table('public.cajas')->insert(['codigo_caja' => $code, 'estado_caja' => $code === 'P001']);
    }
    $user = User::factory()->create(['email' => 'cash@example.test']);
    $user->roles()->attach(Role::query()->where('code', 'admin')->sole());
    $user->setAttribute('puntoventa', 'P001');
    $test->actingAs($user);
    $remote = DB::connection('dtesantacecilia');
    $remote->statement("ATTACH DATABASE ':memory:' AS public");
    $remote->statement('CREATE TABLE public.empleados (empleado_id TEXT PRIMARY KEY, codigo TEXT, correo TEXT)');
    $remote->table('public.empleados')->insert(['empleado_id' => 'TEST1', 'codigo' => 'EMPTEST', 'correo' => $user->email]);
    DB::table('public.inicio_cierre_caja')->insert(['id_caja' => 1, 'fecha' => '2026-11-25', 'hora_inicio' => '07:00:00']);
    DB::table('public.clientes')->insert(['nombre' => 'Cliente de prueba V1']);

    return $user;
}

/** @param array<string, mixed> $changes */
function legacyCashSale(array $changes = []): int
{
    $id = DB::table('public.ventas')->insertGetId(array_replace([
        'numero_factura' => 'legacy-uuid-1', 'numero_control' => 'DTE-01-S004P001-000000000000001',
        'fecha_sistema' => '2026-11-25', 'hora' => '08:00:00', 'total' => '10.00', 'id_cliente' => 1, 'id_usuario' => 45,
        'operacion' => '1', 'sello_recepcion' => 'SELLO-PRUEBA',
    ], $changes), 'id_venta');
    DB::table('public.metodos_pago_venta')->insert(['id_venta' => $id, 'forma_pago' => '01', 'efectivo' => '10.00']);

    return $id;
}

/** @param array<string, mixed> $changes */
function legacyCashReceipt(array $changes = []): int
{
    return DB::table('public.recibos')->insertGetId(array_replace(['nombre_cliente' => 'Persona V1', 'fecha_sistema' => '2026-11-25',
        'numero_caja' => 'P001', 'precio' => '5.00', 'tarjeta' => false, 'codigo_recibo' => 'GENERAL'], $changes));
}
