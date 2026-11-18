<?php

namespace App\Services\Dte;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FactEcClient
{
    public function authenticate(): string
    {
        $username = config('services.factec.username');
        $password = config('services.factec.password');
        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw ValidationException::withMessages(['api' => 'Configura el usuario y contraseña de FactEC en el entorno.']);
        }
        $response = Http::asJson()->acceptJson()->connectTimeout(10)->timeout(45)->withoutRedirecting()
            ->post($this->url('/api/login/'), ['username' => $username, 'password' => $password]);
        $token = $response->json('token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw ValidationException::withMessages(['api' => 'FactEC no confirmó la autenticación (HTTP '.$response->status().'). Revisa las credenciales.']);
        }

        return $token;
    }

    /** @param array<string, mixed> $document */
    public function transmit(array $document, string $token): Response
    {
        $this->ensureTransmissionEnabled();
        if (($document['identificacion']['ambiente'] ?? null) !== config('services.factec.environment')) {
            throw ValidationException::withMessages(['api' => 'El ambiente del DTE no coincide con el ambiente autorizado de FactEC.']);
        }

        return Http::asJson()->acceptJson()->withToken($token)->connectTimeout(10)->timeout(45)->withoutRedirecting()
            ->post($this->url('/api/hacienda/'), [
                'tipo_dte' => $document['identificacion']['tipoDte'],
                'codigo_generacion' => $document['identificacion']['codigoGeneracion'],
                'ambiente' => $document['identificacion']['ambiente'], 'version' => $document['identificacion']['version'],
                'nitemisor' => $document['emisor']['nit'], 'fecha_hora_recibido_emisor' => $document['identificacion']['fecEmi'],
                'codigo_establecimiento' => $document['emisor']['codEstable'], 'codigo_puntoventa' => $document['emisor']['codPuntoVenta'],
                'json_data' => $document,
            ]);
    }

    public function ensureTransmissionEnabled(): void
    {
        if (! config('services.factec.transmission_enabled')) {
            throw ValidationException::withMessages(['api' => 'La transmisión está deshabilitada hasta confirmar la dirección de pruebas de FactEC.']);
        }
    }

    /** @param array<string, mixed> $event */
    public function invalidate(array $event, string $token): Response
    {
        $this->ensureTransmissionEnabled();

        if (($event['identificacion']['ambiente'] ?? null) !== config('services.factec.environment')) {
            throw ValidationException::withMessages(['api' => 'El ambiente de la invalidación no coincide con el autorizado.']);
        }

        return Http::asJson()->acceptJson()->withToken($token)->connectTimeout(10)->timeout(45)->withoutRedirecting()
            ->post($this->url('/api/invalidacion/'), [
                'codigo_generacion' => $event['identificacion']['codigoGeneracion'],
                'ambiente' => $event['identificacion']['ambiente'], 'version' => $event['identificacion']['version'],
                'nitemisor' => $event['emisor']['nit'], 'codigo_establecimiento' => $event['emisor']['codEstable'],
                'codigo_puntoventa' => $event['emisor']['codPuntoVenta'], 'json_data' => $event,
            ]);
    }

    private function url(string $path): string
    {
        $base = config('services.factec.base_url');
        if (! is_string($base) || ! filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages(['api' => 'Configura una dirección HTTPS válida para FactEC.']);
        }

        return rtrim($base, '/').$path;
    }
}
