<?php

use App\Http\Middleware\EnsureUserIsEmpleado;
use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Tests\TestCase;

require_once __DIR__.'/AccessDatabase.php';

function prepareDteGeneration(TestCase $test): void
{
    $test->withoutMiddleware([ValidateSessionWithWorkOS::class, EnsureUserIsEmpleado::class, ResolvePuntoVentaById::class]);
    $user = User::factory()->make(['id' => 7, 'name' => 'Operador', 'email' => 'operador@example.test']);
    $user->setAttribute('puntoventa', 'P004');
    $test->actingAs($user);
    grantTestRoles(auth()->user());
    $db = DB::connection();
    foreach (['public', 'inv', 'libreria'] as $schema) {
        $db->statement("ATTACH DATABASE ':memory:' AS ".$schema);
    }
    $definitions = [
        'public.users' => 'id INTEGER PRIMARY KEY, name TEXT',
        'public.productos' => 'id_producto INTEGER PRIMARY KEY, codigo TEXT, descrip TEXT, grupo TEXT, tipo_prod TEXT, tipo_prec TEXT, unidad TEXT, iva2 TEXT, p_venta NUMERIC, descuento BOOLEAN, c_inv TEXT, c_gas TEXT, c_ing TEXT',
        'public.catalogo_contabilidad' => 'id_catalogo INTEGER PRIMARY KEY, cuenta TEXT',
        'libreria.clientes' => 'id INTEGER PRIMARY KEY, nombre TEXT, credito BOOLEAN, cuenta_contable TEXT',
        'libreria.ventas' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, cliente_id INTEGER NOT NULL, fecha TEXT, caja TEXT, tipo_documento TEXT, uuidv4 TEXT, numero_control TEXT, operacion TEXT, pagos TEXT, plazos TEXT, total NUMERIC, estado TEXT, created_at TEXT, updated_at TEXT',
        'libreria.venta_detalles' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, venta_id INTEGER, producto_id INTEGER, carnet_estudiante TEXT, nombre TEXT, cantidad NUMERIC, valor_unitario NUMERIC, valor_unitario_iva NUMERIC, porcentaje_descuento NUMERIC, valor_descuento NUMERIC, valor_final NUMERIC, cuenta_cargo TEXT, cuenta_abono TEXT, origen TEXT, created_at TEXT, updated_at TEXT',
        'libreria.venta_dtes' => "id INTEGER PRIMARY KEY AUTOINCREMENT, venta_id INTEGER UNIQUE, user_id INTEGER, request_key TEXT UNIQUE, request_hash TEXT, codigo_generacion TEXT UNIQUE, numero_control TEXT UNIQUE, documento TEXT, cliente TEXT, pagos TEXT, inventario_doc_id INTEGER, guardado_externo_at TEXT, created_at TEXT, updated_at TEXT, transmision_estado TEXT DEFAULT 'PENDIENTE', respuesta_api TEXT, transmision_error TEXT, sello_recepcion TEXT, transmision_intento_at TEXT, transmision_confirmada_at TEXT, pagos_aplicados_at TEXT",
        'libreria.venta_cuotas' => "id INTEGER PRIMARY KEY AUTOINCREMENT, venta_id INTEGER, saldo_id TEXT, financiamiento TEXT, carnet_estudiante TEXT, anio INTEGER, mes INTEGER, monto NUMERIC, ambiente TEXT DEFAULT '01', reserva_activa BOOLEAN DEFAULT 1, liberada_at TEXT, UNIQUE(saldo_id,financiamiento,anio,ambiente,reserva_activa)",
        'libreria.venta_invalidaciones' => "id INTEGER PRIMARY KEY AUTOINCREMENT, venta_dte_id INTEGER UNIQUE, user_id INTEGER, codigo_generacion TEXT UNIQUE, request_hash TEXT, documento TEXT, estado TEXT DEFAULT 'PENDIENTE', respuesta_api TEXT, error TEXT, sello_recepcion TEXT, guardado_externo_at TEXT, intento_at TEXT, confirmada_at TEXT, aplicada_at TEXT, inventario_doc_id INTEGER, created_at TEXT, updated_at TEXT",
        'public.historial_dte' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, fecha TEXT, contador INTEGER, dte TEXT, tipo_documento TEXT',
        'public.opciones_factura_electronica' => 'id_ambiente TEXT, id_modelo INTEGER, id_operacion INTEGER',
        'public.opciones_factura_electronica_emisor' => 'nit TEXT, nrc TEXT, nombre TEXT, codigo_act TEXT, desc_act TEXT, nombre_comercial TEXT, depa TEXT, muni TEXT, distrito INTEGER, complemento TEXT, telefono TEXT, correo TEXT, cod_esta_mh TEXT, cod_esta TEXT',
        'inv.inv_bodegas' => 'bodega_id INTEGER PRIMARY KEY, codigo TEXT, activo BOOLEAN',
        'inv.inv_existencias' => 'producto_id INTEGER, bodega_id INTEGER, propiedad TEXT, stock_actual NUMERIC',
        'inv.inv_cat_doc_tipo' => 'doc_tipo TEXT PRIMARY KEY, activo BOOLEAN',
        'inv.inv_cat_tipo_mov' => 'tipo_mov TEXT PRIMARY KEY, afecta_existencia BOOLEAN, afecta_cpp_global BOOLEAN',
        'inv.inv_documentos' => 'doc_id INTEGER PRIMARY KEY AUTOINCREMENT, doc_tipo TEXT, doc_numero TEXT, fecha_doc TEXT, notas TEXT, bodega_id INTEGER, tipo_mov TEXT, UNIQUE(doc_tipo,doc_numero)',
        'inv.inv_documentos_det' => 'doc_det_id INTEGER PRIMARY KEY AUTOINCREMENT, doc_id INTEGER, producto_id INTEGER, bodega_id INTEGER, propiedad TEXT, lote_id INTEGER, tipo_mov TEXT, cantidad NUMERIC, precio_unit NUMERIC, notas TEXT',
    ];
    foreach ($definitions as $table => $columns) {
        $db->statement('CREATE TABLE '.$table.' ('.$columns.')');
    }
    $db->table('public.users')->insert(['id' => 7, 'name' => 'Operador']);
    $db->table('public.catalogo_contabilidad')->insert([['cuenta' => '11010101'], ['cuenta' => '5101']]);
    $db->table('libreria.clientes')->insert(['id' => 1, 'nombre' => 'CLIENTES VARIOS', 'credito' => false, 'cuenta_contable' => null]);
    $db->table('public.productos')->insert(['id_producto' => 1, 'codigo' => 'P1', 'descrip' => 'Producto de prueba', 'grupo' => 'LIBRERIA', 'tipo_prod' => 'B', 'tipo_prec' => 'F', 'unidad' => '59', 'iva2' => 'A', 'p_venta' => '10', 'descuento' => true, 'c_ing' => '5101']);
    $db->table('inv.inv_bodegas')->insert(['bodega_id' => 10, 'codigo' => 'PRINCIPAL', 'activo' => true]);
    $db->table('inv.inv_existencias')->insert([['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'CONSIGNADO', 'stock_actual' => 3], ['producto_id' => 1, 'bodega_id' => 10, 'propiedad' => 'PROPIO', 'stock_actual' => 5]]);
    $db->table('inv.inv_cat_doc_tipo')->insert([['doc_tipo' => 'DTE-01', 'activo' => false], ['doc_tipo' => 'DTE-03', 'activo' => false]]);
    $db->table('inv.inv_cat_tipo_mov')->insert(['tipo_mov' => 'VENTA', 'afecta_existencia' => true, 'afecta_cpp_global' => false]);
    $db->table('public.opciones_factura_electronica')->insert(['id_ambiente' => '00', 'id_modelo' => 1, 'id_operacion' => 1]);
    $db->table('public.opciones_factura_electronica_emisor')->insert(['nit' => '06140000000000', 'nrc' => '1234567', 'nombre' => 'Emisor de prueba', 'codigo_act' => '12345', 'desc_act' => 'Actividad de prueba', 'nombre_comercial' => null, 'depa' => '05', 'muni' => '01', 'distrito' => 96, 'complemento' => 'Dirección de prueba', 'telefono' => '22223333', 'correo' => 'emisor@example.test', 'cod_esta_mh' => 'S004', 'cod_esta' => 'S004']);
    $remote = DB::connection('dtesantacecilia');
    $remote->statement("ATTACH DATABASE ':memory:' AS public");
    $remote->statement('CREATE TABLE public.dte_json (id INTEGER PRIMARY KEY AUTOINCREMENT, dte_id TEXT, resultado TEXT, procesado BOOLEAN DEFAULT 0, error TEXT, respuesta_hacienda TEXT, respuesta2 TEXT, json_invalid TEXT, respuesta_invalid TEXT, invalidado BOOLEAN DEFAULT 0)');
    foreach (['CAT-016' => ['1', '2', '3'], 'CAT-017' => ['01', '02'], 'CAT-018' => ['01'], 'CAT-019' => ['12345'], 'CAT-022' => ['13', '36']] as $table => $codes) {
        $remote->statement('CREATE TABLE public."'.$table.'" (codigo TEXT PRIMARY KEY, valores TEXT)');
        foreach ($codes as $code) {
            $remote->table('public.'.$table)->insert(['codigo' => $code, 'valores' => 'Actividad de prueba']);
        }
    }
    $remote->statement('CREATE TABLE public.municipios (codigo TEXT, departamento_codigo TEXT)');
    $remote->statement('CREATE TABLE public.distritos (id INTEGER, nombre TEXT, departamento_codigo TEXT, municipio_codigo INTEGER)');
    $remote->statement('CREATE TABLE public."CAT-013" (codigo TEXT, valores TEXT, departamento TEXT)');
    $remote->table('public.municipios')->insert(['codigo' => '01', 'departamento_codigo' => '05']);
    $remote->table('public.distritos')->insert(['id' => 96, 'nombre' => 'Santa Tecla', 'departamento_codigo' => '05', 'municipio_codigo' => 1]);
    $remote->table('public.CAT-013')->insert(['codigo' => '11', 'valores' => 'SANTA TECLA', 'departamento' => '05']);
}

function dteSalePayload(): array
{
    return ['requestKey' => '1c197111-a913-4a26-b717-23036541758a', 'document' => ['type' => '01', 'date' => '2026-11-11', 'discount' => 0, 'account' => '11010101'],
        'client' => ['id' => 1, 'name' => 'Cliente de la factura', 'documentType' => null, 'document' => null, 'email' => null],
        'items' => [['productId' => 1, 'quantity' => '5', 'netPrice' => '0.01', 'discount' => 0]],
        'operation' => '1', 'installments' => 1, 'term' => '01', 'total' => '56.50', 'payments' => [['method' => '01', 'amount' => '60', 'reference' => null]], 'observation' => 'Venta de prueba'];
}

function prepareAcademicGeneration(): void
{
    config(['database.connections.plataforma' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('plataforma');
    $platform = DB::connection('plataforma');
    $platform->statement('CREATE TABLE students (student_id INTEGER PRIMARY KEY, first_name TEXT, middle_name TEXT, last_name TEXT)');
    $platform->statement('CREATE TABLE student_enrollment (student_id INTEGER, syear INTEGER, grade_id INTEGER, especialidad TEXT, seccion TEXT)');
    $platform->statement('CREATE TABLE school_gradelevels (id INTEGER PRIMARY KEY, title TEXT)');
    $platform->statement('CREATE TABLE saldo_pagos (id_saldo INTEGER PRIMARY KEY, student_id INTEGER, syear INTEGER, mes INTEGER, codigo_barra TEXT, cuota NUMERIC, pago NUMERIC, saldo NUMERIC, becado BOOLEAN, cuota_beca NUMERIC, pago_beca NUMERIC, saldo_beca NUMERIC, cuota_bienhechor NUMERIC, pago_bienhechor NUMERIC, saldo_bienhechor NUMERIC, fecha_actualizado TEXT, habilitado INTEGER)');
    $platform->statement('CREATE TABLE detalle_pagos (id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, student_id INTEGER, mes_pagado INTEGER, fecha TEXT, monto NUMERIC, lugar TEXT, syear INTEGER, uid_dte TEXT, fecha_emision_dte TEXT, numero_dte TEXT, facturado BOOLEAN, pago_beca BOOLEAN, pago_bienhechor BOOLEAN, estado TEXT, updated_at TEXT)');
    foreach ([101, 102] as $student) {
        $platform->table('students')->insert(['student_id' => $student, 'first_name' => 'Alumno', 'last_name' => (string) $student]);
        $platform->table('student_enrollment')->insert(['student_id' => $student, 'syear' => 2025, 'grade_id' => 1, 'especialidad' => '', 'seccion' => 'A']);
        $platform->table('saldo_pagos')->insert(['id_saldo' => $student, 'student_id' => $student, 'syear' => 2025, 'mes' => 11, 'codigo_barra' => 'CUOTA-'.$student, 'cuota' => 50, 'pago' => 0, 'saldo' => 50, 'becado' => true, 'cuota_beca' => 25, 'pago_beca' => 0, 'saldo_beca' => 25]);
    }
    $platform->table('school_gradelevels')->insert(['id' => 1, 'title' => 'Primer grado']);
    DB::statement('CREATE TABLE libreria.servicios_academicos (grado_id INTEGER, especialidad TEXT, financiamiento TEXT, concepto TEXT, producto_id INTEGER)');
    DB::table('public.productos')->insert(['id_producto' => 2, 'codigo' => 'COL-01', 'descrip' => 'Colegiatura', 'grupo' => 'COLEGIATURA', 'tipo_prod' => 'S', 'tipo_prec' => 'F', 'unidad' => '99', 'iva2' => 'E', 'p_venta' => 0, 'descuento' => false, 'c_ing' => '5101']);
    foreach (['normal', 'beca'] as $funding) {
        DB::table('libreria.servicios_academicos')->insert(['grado_id' => 1, 'especialidad' => '', 'financiamiento' => $funding, 'concepto' => 'colegiatura', 'producto_id' => 2]);
    }
}
