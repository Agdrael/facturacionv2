<?php

use App\Services\GeographyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.connections.dtesantacecilia' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('dtesantacecilia');
});

function prepareFiscalGeography(): void
{
    $catalog = DB::connection('dtesantacecilia');
    $catalog->statement("ATTACH DATABASE ':memory:' AS public");
    $catalog->statement('CREATE TABLE public.municipios (codigo TEXT, departamento_codigo TEXT)');
    $catalog->statement('CREATE TABLE public.distritos (id INTEGER PRIMARY KEY, nombre TEXT, departamento_codigo TEXT, municipio_codigo INTEGER)');
    $catalog->statement('CREATE TABLE public."CAT-013" (codigo TEXT, valores TEXT, departamento TEXT)');
    $catalog->table('public.municipios')->insert([
        ['codigo' => '28', 'departamento_codigo' => '05'], ['codigo' => '23', 'departamento_codigo' => '06'],
        ['codigo' => '18', 'departamento_codigo' => '07'], ['codigo' => '30', 'departamento_codigo' => '14'],
    ]);
    $catalog->table('public.distritos')->insert([
        ['id' => 96, 'nombre' => 'Santa Tecla', 'departamento_codigo' => '05', 'municipio_codigo' => 28],
        ['id' => 108, 'nombre' => 'San Salvador', 'departamento_codigo' => '06', 'municipio_codigo' => 23],
        ['id' => 125, 'nombre' => 'El Carmen', 'departamento_codigo' => '07', 'municipio_codigo' => 18],
        ['id' => 260, 'nombre' => 'El Carmen', 'departamento_codigo' => '14', 'municipio_codigo' => 30],
    ]);
    $catalog->table('public.CAT-013')->insert([
        ['codigo' => '11', 'valores' => 'SANTA TECLA', 'departamento' => '05'],
        ['codigo' => '14', 'valores' => 'SAN SALVADOR', 'departamento' => '06'],
        ['codigo' => '03', 'valores' => 'EL CARMEN', 'departamento' => '07'],
        ['codigo' => '05', 'valores' => 'EL CARMEN', 'departamento' => '14'],
    ]);
}

test('fiscal addresses use the official district code instead of its internal identifier', function (string $department, string $municipality, string $id, string $code) {
    prepareFiscalGeography();

    $address = app(GeographyService::class)->fiscalAddress($department, $municipality, $id, 'Dirección de prueba');

    expect($address)->toBe(['departamento' => $department, 'municipio' => $municipality, 'distrito' => $code, 'complemento' => 'Dirección de prueba']);
})->with([
    ['05', '28', '96', '11'], ['06', '23', '108', '14'],
    ['07', '18', '125', '03'], ['14', '30', '260', '05'],
]);

test('an invalid geographical combination cannot be exported to Hacienda', function (string $department, string $municipality, string $id) {
    prepareFiscalGeography();

    expect(fn () => app(GeographyService::class)->fiscalAddress($department, $municipality, $id, 'Dirección'))
        ->toThrow(ValidationException::class);
})->with([['05', '23', '108'], ['06', '28', '108'], ['06', '23', '999']]);

test('districts without an unambiguous fiscal mapping are blocked instead of exporting an ID', function (string $value, string $code) {
    prepareFiscalGeography();
    DB::connection('dtesantacecilia')->table('public.CAT-013')->where('departamento', '06')->update(['valores' => $value, 'codigo' => $code]);

    expect(fn () => app(GeographyService::class)->fiscalAddress('06', '23', '108', 'Dirección'))
        ->toThrow(ValidationException::class, 'No hay un código fiscal único');
})->with([['Nombre diferente', '14'], ['SAN SALVADOR', '108']]);

test('conflicting codes for the same department and district cannot be chosen arbitrarily', function () {
    prepareFiscalGeography();
    DB::connection('dtesantacecilia')->table('public.CAT-013')->insert(['codigo' => '15', 'valores' => 'San Salvador', 'departamento' => '06']);

    expect(fn () => app(GeographyService::class)->fiscalAddress('06', '23', '108', 'Dirección'))
        ->toThrow(ValidationException::class, 'No hay un código fiscal único');
});

test('case accents and repeated spaces do not prevent an exact name correspondence', function () {
    prepareFiscalGeography();
    DB::connection('dtesantacecilia')->table('public.distritos')->where('id', 96)->update(['nombre' => 'Sánta   Tecla']);

    expect(app(GeographyService::class)->fiscalAddress('05', '28', '96', 'Dirección')['distrito'])->toBe('11');
});
