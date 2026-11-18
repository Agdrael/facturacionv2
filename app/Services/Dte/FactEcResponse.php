<?php

namespace App\Services\Dte;

class FactEcResponse
{
    /** @return array{state: string, seal: ?string, message: string} */
    public static function interpret(string $raw, int $http, string $uuid, string $environment): array
    {
        $unknown = ['state' => 'REVISION', 'seal' => null, 'message' => 'Resultado incierto. Consulta la respuesta antes de intentar una nueva transmisión.'];
        $data = json_decode($raw, true);
        if ($http < 200 || $http >= 300 || ! is_array($data)) {
            return $unknown;
        }
        $body = is_array($data['body'] ?? null) ? $data['body'] : [];
        $hacienda = is_array($body['respuestaHacienda'] ?? null) ? $body['respuestaHacienda'] : (is_array($data['respuestaHacienda'] ?? null) ? $data['respuestaHacienda'] : []);
        $state = $body['estado'] ?? $hacienda['estado'] ?? null;
        $uuidConfirmed = false;
        $environmentConfirmed = false;
        foreach ([$body, $hacienda, $data] as $part) {
            if (isset($part['codigoGeneracion']) && (! is_string($part['codigoGeneracion']) || strcasecmp($part['codigoGeneracion'], $uuid) !== 0)) {
                return ['state' => 'REVISION', 'seal' => null, 'message' => 'La respuesta corresponde a otro código de generación.'];
            }
            if (isset($part['codigoGeneracion'])) {
                $uuidConfirmed = true;
            }
            if (isset($part['ambiente'])) {
                if ($part['ambiente'] !== $environment) {
                    return ['state' => 'REVISION', 'seal' => null, 'message' => 'FactEC respondió desde otro ambiente. Revisa la configuración del servidor antes de transmitir nuevamente.'];
                }
                $environmentConfirmed = true;
            }
        }
        if ($state === 'RECHAZADO') {
            $description = $hacienda['descripcionMsg'] ?? $body['descripcionMsg'] ?? 'Revisa la respuesta de FactEC.';

            return ['state' => 'RECHAZADO', 'seal' => null, 'message' => 'DTE rechazado: '.(is_string($description) ? $description : 'Revisa la respuesta de FactEC.')];
        }
        $seal = $hacienda['selloRecibido'] ?? $hacienda['selloRecepcion'] ?? $body['selloRecibido'] ?? $body['selloRecepcion'] ?? null;
        $completed = in_array($state, ['PROCESADO', 'RECIBIDO'], true) || ($body['estadoProceso'] ?? null) === 'Proceso completado con éxito';
        if (($data['status'] ?? null) === 'OK' && $completed && $uuidConfirmed && $environmentConfirmed && is_string($seal) && trim($seal) !== '') {
            return ['state' => 'ACEPTADO', 'seal' => trim($seal), 'message' => 'DTE aceptado con sello de recepción.'];
        }

        return $unknown;
    }
}
