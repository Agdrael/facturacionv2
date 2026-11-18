<?php

use App\Services\Dte\FactEcResponse;

test('a confirmed Hacienda response needs both acceptance and a seal', function (array $data, string $expected) {
    $result = FactEcResponse::interpret(json_encode($data), 200, 'ABC', '00');
    expect($result['state'])->toBe($expected);
})->with([
    'processed with seal' => [['status' => 'OK', 'body' => ['respuestaHacienda' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SEAL', 'codigoGeneracion' => 'ABC', 'ambiente' => '00']]], 'ACEPTADO'],
    'received without seal' => [['status' => 'OK', 'body' => ['estado' => 'RECIBIDO']], 'REVISION'],
    'processed without seal' => [['status' => 'OK', 'body' => ['estado' => 'PROCESADO']], 'REVISION'],
    'queued OK' => [['status' => 'OK'], 'REVISION'],
    'explicit rejection' => [['status' => 'ERROR', 'body' => ['estado' => 'RECHAZADO', 'descripcionMsg' => 'Formato inválido']], 'RECHAZADO'],
    'another uuid' => [['status' => 'OK', 'body' => ['respuestaHacienda' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SEAL', 'codigoGeneracion' => 'OTHER']]], 'REVISION'],
    'nested reception' => [['status' => 'OK', 'body' => ['estadoProceso' => 'Proceso completado con éxito', 'respuestaHacienda' => ['selloRecibido' => 'SEAL', 'codigoGeneracion' => 'ABC', 'ambiente' => '00']]], 'ACEPTADO'],
    'seal without environment' => [['status' => 'OK', 'body' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SEAL', 'codigoGeneracion' => 'ABC']], 'REVISION'],
    'seal without UUID' => [['status' => 'OK', 'body' => ['estado' => 'PROCESADO', 'selloRecibido' => 'SEAL', 'ambiente' => '00']], 'REVISION'],
    'observed real environment mismatch' => [['status' => 'OK', 'body' => ['ambiente' => '01', 'estado' => 'RECHAZADO', 'codigoGeneracion' => null, 'selloRecibido' => null, 'codigoMsg' => '094', 'descripcionMsg' => 'PARAMETROS NO SON VALIDOS', 'observaciones' => ['[peticion.ambiente] VALOR NO VALIDO']]], 'REVISION'],
]);

test('an HTTP error or malformed response cannot confirm a payment', function (string $raw, int $http) {
    expect(FactEcResponse::interpret($raw, $http, 'ABC', '00')['state'])->toBe('REVISION');
})->with([['<html>Error</html>', 200], ['{"status":"OK","body":{"estado":"PROCESADO","selloRecibido":"SEAL"}}', 500]]);
