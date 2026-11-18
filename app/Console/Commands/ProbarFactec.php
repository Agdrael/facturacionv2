<?php

namespace App\Console\Commands;

use App\Services\Dte\DteTransmissionService;
use App\Services\Dte\FactEcClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

#[Signature('factec:probar {--dte= : ID, código de generación o número de control del DTE registrado en Laravel}')]
#[Description('Prueba la autenticación de FactEC o transmite/consulta un DTE existente, solo en ambiente 00')]
class ProbarFactec extends Command
{
    public function handle(FactEcClient $client, DteTransmissionService $transmitter): int
    {
        if (config('services.factec.environment') !== '00') {
            $this->error('Este comando solo permite el ambiente 00 (pruebas).');

            return self::FAILURE;
        }

        $this->info('FactEC · Ambiente 00 (pruebas)');

        try {
            $reference = $this->option('dte');
            if ($reference === null) {
                $client->authenticate();
                $this->info('Autenticación correcta. No se transmitió ningún DTE.');
                if (! config('services.factec.transmission_enabled')) {
                    $this->warn('La transmisión de documentos continúa deshabilitada hasta confirmar la dirección de pruebas de FactEC.');
                }

                return self::SUCCESS;
            }

            if (! is_string($reference) || trim($reference) === '') {
                $this->error('Indica un ID, código de generación o número de control después de --dte=.');

                return self::FAILURE;
            }

            $reference = trim($reference);
            $id = filter_var($reference, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $column = 'id';
                $value = $id;
            } elseif (Str::isUuid($reference)) {
                $column = 'codigo_generacion';
                $value = strtoupper($reference);
            } elseif (preg_match('/^DTE-\d{2}-[A-Z0-9]{8}-\d{15}$/D', $reference) === 1) {
                $column = 'numero_control';
                $value = $reference;
            } else {
                $this->error('La referencia del DTE no es válida. Usa su ID, UUID o número de control completo.');

                return self::FAILURE;
            }

            $journal = DB::table('libreria.venta_dtes')->where($column, $value)->first(['id', 'numero_control']);
            if ($journal === null) {
                $this->error('No se encontró ese DTE entre los documentos generados en Laravel.');

                return self::FAILURE;
            }

            $this->line('Documento: '.$journal->numero_control);
            $result = $transmitter->transmit($journal->id);
            $this->line('Estado: '.$result['transmissionState']);
            if ($result['transmissionState'] === 'ACEPTADO_PRUEBA') {
                $this->info($result['message']);

                return self::SUCCESS;
            }

            $this->error($result['message']);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }
        } catch (ConnectionException) {
            $this->error('No se pudo conectar con FactEC. Revisa la conexión e intenta nuevamente la autenticación.');
        } catch (Throwable $error) {
            report($error);
            $this->error('No se pudo completar la prueba. Revisa el registro de Laravel para conocer el detalle.');
        }

        return self::FAILURE;
    }
}
