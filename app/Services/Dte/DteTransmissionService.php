<?php

namespace App\Services\Dte;

use App\Services\Libreria\AcademicPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Throwable;

class DteTransmissionService
{
    public function __construct(private FactEcClient $client, private AcademicPaymentService $payments, private DteStorageService $storage, private DteProcessLockService $lock) {}

    /** @return array<string, mixed> */
    public function transmit(int $id): array
    {
        return $this->lock->run($id, fn (): array => $this->transmitLocked($id));
    }

    /** @return array<string, mixed> */
    private function transmitLocked(int $id): array
    {
        $journal = $this->journal($id);
        if (DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $id)->exists()) {
            throw ValidationException::withMessages(['api' => 'Este DTE tiene una solicitud de invalidación. Consulta esa solicitud desde el historial.']);
        }
        $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
        if ($document['identificacion']['ambiente'] !== config('services.factec.environment')) {
            throw ValidationException::withMessages(['api' => 'El DTE pertenece a otro ambiente. En esta configuración solo se permite '.config('services.factec.environment').'.']);
        }
        if (in_array($journal->transmision_estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true)) {
            return $this->finish($id);
        }
        $this->storage->save($id);
        $remote = DB::connection('dtesantacecilia');
        $locked = false;
        if ($remote->getDriverName() === 'pgsql') {
            $locked = (bool) $remote->selectOne('SELECT pg_try_advisory_lock(62026010) AS acquired')->acquired;
            if (! $locked) {
                throw ValidationException::withMessages(['api' => 'Otro transmisor está trabajando. Intenta nuevamente al finalizar.']);
            }
        }
        try {
            $journal = $this->journal($id);
            $rows = $remote->table('public.dte_json')->where('dte_id', $journal->codigo_generacion)->get();
            if ($rows->count() !== 1 || json_decode($rows->first()->resultado, true, 512, JSON_THROW_ON_ERROR) != $document) {
                throw new RuntimeException('El JSON del almacén DTE no coincide con el generado en Laravel.');
            }
            $row = $rows->first();
            if (! empty($row->respuesta_hacienda) || ! empty($row->respuesta2)) {
                $raw = ! empty($row->respuesta2) ? (is_string($row->respuesta2) ? $row->respuesta2 : json_encode($row->respuesta2, JSON_THROW_ON_ERROR)) : $row->respuesta_hacienda;
                $sameResponse = $journal->respuesta_api !== null && (hash_equals($journal->respuesta_api, $raw) || (is_array(json_decode($raw, true)) && json_decode($raw, true) == json_decode($journal->respuesta_api, true)));
                if (! $sameResponse) {
                    $result = FactEcResponse::interpret($raw, 200, $journal->codigo_generacion, $document['identificacion']['ambiente']);
                    if ($result['state'] === 'ACEPTADO' && ! empty($row->error)) {
                        $result = ['state' => 'REVISION', 'seal' => null, 'message' => 'Hay una respuesta con sello, pero el transmisor registró un error. Requiere conciliación.'];
                    }
                    $this->record($id, $raw, $result);
                }

                return $this->finish($id);
            }
            if ($journal->transmision_estado !== 'PENDIENTE' || $row->procesado) {
                if ($journal->transmision_estado === 'PENDIENTE') {
                    $this->record($id, '', ['state' => 'REVISION', 'seal' => null, 'message' => 'El transmisor anterior apartó el DTE sin una confirmación verificable.']);
                }

                return $this->finish($id);
            }
            $this->client->ensureTransmissionEnabled();
            $token = $this->client->authenticate();
            $claimed = $remote->transaction(function () use ($remote, $id, $row): bool {
                $current = $remote->table('public.dte_json')->where('id', $row->id)->lockForUpdate()->first();
                if ($current === null || $current->procesado) {
                    return false;
                }
                if (DB::table('libreria.venta_dtes')->where('id', $id)->where('transmision_estado', 'PENDIENTE')->update(['transmision_estado' => 'ENVIANDO', 'transmision_intento_at' => now(), 'updated_at' => now()]) !== 1) {
                    return false;
                }
                $remote->table('public.dte_json')->where('id', $row->id)->update(['procesado' => true, 'error' => 'Transmisión iniciada; pendiente de confirmación. No reenviar automáticamente.']);

                return true;
            });
            if (! $claimed) {
                return $this->finish($id);
            }
            try {
                $response = $this->client->transmit($document, $token);
                $raw = $response->body();
                $result = FactEcResponse::interpret($raw, $response->status(), $journal->codigo_generacion, $document['identificacion']['ambiente']);
            } catch (Throwable $error) {
                report($error);
                $raw = '';
                $result = ['state' => 'REVISION', 'seal' => null, 'message' => 'No se recibió una respuesta concluyente. Requiere revisión; el documento no se reenviará automáticamente.'];
            }
            $this->record($id, $raw, $result);
            $remote->table('public.dte_json')->where('id', $row->id)->update([
                'procesado' => true, 'error' => $result['state'] === 'ACEPTADO' ? null : $result['message'],
                'respuesta_hacienda' => $raw === '' ? null : $raw,
                'respuesta2' => is_array(json_decode($raw, true)) ? $raw : null,
            ]);
        } finally {
            if ($locked) {
                $remote->select('SELECT pg_advisory_unlock(62026010)');
            }
        }

        return $this->finish($id);
    }

    /** @param array{state: string, seal: ?string, message: string} $result */
    private function record(int $id, string $raw, array $result): void
    {
        $journal = $this->journal($id);
        if (in_array($journal->transmision_estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true)) {
            return;
        }
        $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
        $state = $result['state'] === 'ACEPTADO' && $document['identificacion']['ambiente'] === '00' ? 'ACEPTADO_PRUEBA' : $result['state'];
        DB::table('libreria.venta_dtes')->where('id', $id)->update([
            'transmision_estado' => $state, 'respuesta_api' => $raw === '' ? null : $raw, 'sello_recepcion' => $result['seal'],
            'transmision_error' => $result['state'] === 'ACEPTADO' ? null : $result['message'],
            'transmision_confirmada_at' => $result['state'] === 'ACEPTADO' ? now() : null, 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function finish(int $id): array
    {
        $journal = $this->journal($id);
        if ($journal->transmision_estado === 'ACEPTADO') {
            try {
                $this->payments->apply($journal);
            } catch (Throwable $error) {
                report($error);
                DB::table('libreria.venta_dtes')->where('id', $id)->update(['transmision_error' => 'DTE aceptado; falta conciliar y aplicar los pagos de la plataforma.', 'updated_at' => now()]);
            }
        }
        $journal = $this->journal($id);
        $message = match ($journal->transmision_estado) {
            'ACEPTADO_PRUEBA' => 'DTE aceptado en pruebas con sello. Los saldos de la plataforma permanecen sin cambios.',
            'ACEPTADO' => $journal->pagos_aplicados_at === null ? $journal->transmision_error : 'DTE aceptado y pagos aplicados correctamente.',
            'RECHAZADO','REVISION' => $journal->transmision_error,
            'ENVIANDO' => 'Hay una transmisión en curso o interrumpida. Consulta su respuesta antes de volver a enviar.',
            default => 'DTE pendiente de transmisión.',
        };

        return ['transmissionState' => $journal->transmision_estado, 'seal' => $journal->sello_recepcion, 'paymentsApplied' => $journal->pagos_aplicados_at !== null, 'message' => $message];
    }

    private function journal(int $id): stdClass
    {
        $row = DB::table('libreria.venta_dtes')->where('id', $id)->first();
        if ($row === null) {
            throw new RuntimeException('DTE no encontrado.');
        }

        return $row;
    }
}
