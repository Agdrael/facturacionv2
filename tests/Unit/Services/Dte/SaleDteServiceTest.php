<?php

use App\Services\Dte\SaleDteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('sqlite');
    DB::purge('dtesantacecilia');
    $this->travelTo(now('America/El_Salvador')->setDate(2026, 11, 11)->setTime(10, 30));
});

function prepareDteBuilder(): void
{
    DB::statement("ATTACH DATABASE ':memory:' AS public");
    DB::statement('CREATE TABLE public.opciones_factura_electronica (id_ambiente TEXT,id_modelo INTEGER,id_operacion INTEGER)');
    DB::table('public.opciones_factura_electronica')->insert(['id_ambiente' => '00', 'id_modelo' => 1, 'id_operacion' => 1]);
    DB::statement('CREATE TABLE public.opciones_factura_electronica_emisor (nit TEXT,nrc TEXT,nombre TEXT,codigo_act TEXT,desc_act TEXT,nombre_comercial TEXT,depa TEXT,muni TEXT,distrito INTEGER,complemento TEXT,telefono TEXT,correo TEXT,cod_esta TEXT)');
    DB::table('public.opciones_factura_electronica_emisor')->insert(['nit' => '0614-000000-000-0', 'nrc' => '1234567', 'nombre' => 'Emisor de pruebas', 'codigo_act' => '12345', 'desc_act' => 'Actividad de prueba', 'depa' => '05', 'muni' => '01', 'distrito' => 96, 'complemento' => 'Dirección de pruebas', 'telefono' => '22223333', 'correo' => 'emisor@example.test', 'cod_esta' => 'S004']);
    $remote = DB::connection('dtesantacecilia');
    $remote->statement("ATTACH DATABASE ':memory:' AS public");
    $remote->statement('CREATE TABLE public."CAT-019" (codigo TEXT,valores TEXT)');
    $remote->table('public.CAT-019')->insert(['codigo' => '12345', 'valores' => 'Actividad de prueba']);
    $remote->statement('CREATE TABLE public.municipios (codigo TEXT, departamento_codigo TEXT)');
    $remote->table('public.municipios')->insert(['codigo' => '01', 'departamento_codigo' => '05']);
    $remote->statement('CREATE TABLE public.distritos (id INTEGER, nombre TEXT, departamento_codigo TEXT, municipio_codigo INTEGER)');
    $remote->table('public.distritos')->insert(['id' => 96, 'nombre' => 'Santa Tecla', 'departamento_codigo' => '05', 'municipio_codigo' => 1]);
    $remote->statement('CREATE TABLE public."CAT-013" (codigo TEXT, valores TEXT, departamento TEXT)');
    $remote->table('public.CAT-013')->insert(['codigo' => '11', 'valores' => 'SANTA TECLA', 'departamento' => '05']);
}

function dteBuilderData(string $type): array
{
    $data = ['document' => ['type' => $type, 'date' => '2026-11-11', 'discount' => 0], 'operation' => '1', 'observation' => 'Observación de prueba',
        'client' => ['name' => 'Receptor de pruebas', 'document' => '0614-000000-000-0', 'documentType' => '36', 'nrc' => '1234567', 'activity' => '12345', 'department' => '05', 'municipality' => '01', 'district' => '96', 'address' => 'Dirección del receptor', 'phone' => '22224444', 'email' => 'receptor@example.test'],
        'payments' => [['method' => '01', 'amount' => '20']]];
    $calculation = ['lines' => [['productType' => 'B', 'code' => 'P1', 'name' => 'Producto de prueba', 'quantity' => '1', 'unit' => 59, 'netPrice' => '10', 'grossPrice' => '11.3', 'itemDiscount' => '0', 'amount' => $type === '01' ? '11.3' : '10', 'taxable' => true]],
        'total' => '11.30', 'paid' => '20', 'change' => '8.70', 'exempt' => '0', 'taxed' => $type === '01' ? '11.30' : '10.00', 'exemptDiscount' => '0', 'taxedDiscount' => '0', 'itemDiscount' => '0', 'subtotal' => $type === '01' ? '11.30' : '10.00', 'vat' => '1.30'];

    return [$data, $calculation];
}

test('approved DTE versions include the new address fields and omit obsolete extension and issuer fields', function (string $type, int $version) {
    prepareDteBuilder();
    [$data,$calculation] = dteBuilderData($type);
    $document = app(SaleDteService::class)->build($data, $calculation, 'ABCDEF12-1234-4123-8123-123456789ABC', 'DTE-'.$type.'-S004P004-000000000000001', 'P004');

    expect($document)->toHaveKeys(['identificacion', 'documentoRelacionado', 'emisor', 'receptor', 'otrosDocumentos', 'ventaTercero', 'cuerpoDocumento', 'resumen', 'apendice'])->not->toHaveKey('extension')
        ->and($document['identificacion']['version'])->toBe($version)
        ->and($document['emisor']['direccion'])->toBe(['departamento' => '05', 'municipio' => '01', 'distrito' => '11', 'complemento' => 'Dirección de pruebas'])
        ->and($document['receptor']['direccion']['distrito'])->toBe('11')
        ->and($document['emisor'])->not->toHaveKeys(['tipoEstablecimiento', 'codEstableMH', 'codPuntoVentaMH'])
        ->and($document['resumen']['observaciones'])->toBe('Observación de prueba')
        ->and($document['resumen']['pagos'][0]['montoPago'])->toBe(11.3)
        ->and($document['resumen'])->toHaveKey('ivaRete')->not->toHaveKeys(['ivaRete1', 'reteRenta']);
    if ($type === '01') {
        expect($document['cuerpoDocumento'][0]['precioUni'])->toBe(11.3)->and($document['cuerpoDocumento'][0]['ivaItem'])->toBe(1.3)
            ->and($document['resumen']['totalIva'])->toBe(1.3);
    } else {
        expect($document['cuerpoDocumento'][0]['precioUni'])->toBe(10.0)->and($document['cuerpoDocumento'][0]['tributos'])->toBe(['20'])
            ->and($document['cuerpoDocumento'][0])->not->toHaveKey('ivaItem')->and($document['resumen'])->toHaveKey('ivaPerci')->not->toHaveKey('totalIva');
    }
})->with(['factura' => ['01', 2], 'credito fiscal' => ['03', 4]]);

test('a credit operation keeps the collected amount separate from its outstanding term', function () {
    prepareDteBuilder();
    [$data,$calculation] = dteBuilderData('03');
    $data['operation'] = '2';
    $data['installments'] = 3;
    $data['term'] = '01';
    $data['payments'] = [['method' => '01', 'amount' => '5']];
    $calculation['paid'] = '5';
    $calculation['change'] = '0';
    $document = app(SaleDteService::class)->build($data, $calculation, 'ABCDEF12-1234-4123-8123-123456789ABC', 'DTE-03-S004P004-000000000000001', 'P004');

    expect($document['resumen']['pagos'])->toBe([
        ['codigo' => '01', 'montoPago' => 5.0, 'referencia' => null, 'plazo' => null, 'periodo' => null],
        ['codigo' => null, 'montoPago' => 6.3, 'referencia' => null, 'plazo' => '01', 'periodo' => 3],
    ]);
});

test('exempt services carry no VAT or taxable tribute in a credit invoice', function () {
    prepareDteBuilder();
    [$data,$calculation] = dteBuilderData('03');
    $calculation['lines'][0] = [...$calculation['lines'][0], 'productType' => 'S', 'taxable' => false];
    $calculation['exempt'] = '10';
    $calculation['taxed'] = '0';
    $calculation['total'] = '10';
    $calculation['vat'] = '0';
    $calculation['change'] = '10';
    $document = app(SaleDteService::class)->build($data, $calculation, 'ABCDEF12-1234-4123-8123-123456789ABC', 'DTE-03-S004P004-000000000000001', 'P004');

    expect($document['cuerpoDocumento'][0]['tipoItem'])->toBe(2)->and($document['cuerpoDocumento'][0]['ventaExenta'])->toBe(10.0)
        ->and($document['cuerpoDocumento'][0]['ventaGravada'])->toBe(0)->and($document['cuerpoDocumento'][0]['tributos'])->toBeNull()->and($document['resumen']['tributos'])->toBeNull();
});

test('the rejected San Salvador receiver exports district 14 while the Santa Tecla emitter exports 11', function () {
    prepareDteBuilder();
    $catalog = DB::connection('dtesantacecilia');
    $catalog->table('public.municipios')->insert(['codigo' => '23', 'departamento_codigo' => '06']);
    $catalog->table('public.distritos')->insert(['id' => 108, 'nombre' => 'San Salvador', 'departamento_codigo' => '06', 'municipio_codigo' => 23]);
    $catalog->table('public.CAT-013')->insert(['codigo' => '14', 'valores' => 'SAN SALVADOR', 'departamento' => '06']);
    [$data, $calculation] = dteBuilderData('01');
    $data['client'] = [...$data['client'], 'department' => '06', 'municipality' => '23', 'district' => '108'];

    $document = app(SaleDteService::class)->build($data, $calculation, 'ABCDEF12-1234-4123-8123-123456789ABC', 'DTE-01-S004P004-000000000000001', 'P004');

    expect($document['receptor']['direccion']['distrito'])->toBe('14');
    expect($document['emisor']['direccion']['distrito'])->toBe('11');
    expect($data['client']['district'])->toBe('108');
});
