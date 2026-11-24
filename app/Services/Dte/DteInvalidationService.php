<?php

namespace App\Services\Dte;

use App\Services\Libreria\ReceiptCreditService;
use App\Services\Libreria\SaleReversalService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use stdClass;
use Throwable;

class DteInvalidationService
{
    public function __construct(private FactEcClient $client, private SaleReversalService $reversal, private DteProcessLockService $lock, private ReceiptCreditService $credits) {}

    /** @param array<string, mixed> $data */
    public function prepare(int $journalId, array $data, int $userId): void
    {
        unset($data['confirmed']);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $this->lock->run($journalId, function () use ($journalId, $data, $userId, $hash): void {
            DB::transaction(function () use ($journalId, $data, $userId, $hash): void {
                $journal = $this->journal($journalId);
                if (config('receipts.enabled')) {
                    $this->credits->assertNoReceipts($journal->codigo_generacion);
                }
                $existing = DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $journalId)->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $hash)) {
                        throw ValidationException::withMessages(['api' => 'Ya existe un evento para este DTE. Consulta su respuesta antes de solicitar otro.']);
                    }

                    return;
                }
                $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
                $this->ensureEligible($journal, $document);
                $replacement = empty($data['replacement']) ? null : Str::upper($data['replacement']);
                if ($replacement !== null) {
                    $other = DB::table('libreria.venta_dtes')->where('codigo_generacion', $replacement)->first();
                    $otherDocument = $other === null ? null : json_decode($other->documento, true, 512, JSON_THROW_ON_ERROR);
                    if (
                        $other === null || (int) $other->id === $journalId || ! in_array($other->transmision_estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true) || empty($other->sello_recepcion)
                        || $otherDocument['identificacion']['ambiente'] !== $document['identificacion']['ambiente']
                        || $otherDocument['identificacion']['tipoDte'] !== $document['identificacion']['tipoDte']
                        || $otherDocument['emisor']['nit'] !== $document['emisor']['nit']
                        || DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $other->id)->exists()
                    ) {
                        throw ValidationException::withMessages(['replacement' => 'Selecciona un DTE de reemplazo aceptado, del mismo tipo, emisor y ambiente, sin solicitud de invalidación.']);
                    }
                }
                $uuid = Str::upper((string) Str::uuid());
                $event = $this->build($journal, $document, $data, $uuid, $replacement);
                DB::table('libreria.venta_invalidaciones')->insert([
                    'venta_dte_id' => $journalId,
                    'user_id' => $userId,
                    'codigo_generacion' => $uuid,
                    'request_hash' => $hash,
                    'documento' => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });
    }

    public function save(int $journalId): void
    {
        $event = $this->event($journalId);
        $journal = $this->journal($journalId);
        $remote = DB::connection('dtesantacecilia');
        $remote->transaction(function () use ($remote, $event, $journal): void {
            $rows = $remote->table('public.dte_json')->where('dte_id', $journal->codigo_generacion)->lockForUpdate()->get();
            if ($rows->count() !== 1 || json_decode($rows->first()->resultado, true, 512, JSON_THROW_ON_ERROR) != json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR)) {
                throw new RuntimeException('El almacén DTE no contiene una copia única del documento original.');
            }
            $row = $rows->first();
            if (! empty($row->json_invalid) && json_decode($row->json_invalid, true, 512, JSON_THROW_ON_ERROR) != json_decode($event->documento, true, 512, JSON_THROW_ON_ERROR)) {
                throw ValidationException::withMessages(['api' => 'Ya existe otro evento de invalidación en dte_json. Requiere conciliación.']);
            }
            $remote->table('public.dte_json')->where('id', $row->id)->update(['json_invalid' => $event->documento]);
        });
        DB::table('libreria.venta_invalidaciones')->where('id', $event->id)->update(['guardado_externo_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    public function transmit(int $journalId): array
    {
        return $this->lock->run($journalId, function () use ($journalId): array {
            $event = $this->event($journalId);
            $document = json_decode($event->documento, true, 512, JSON_THROW_ON_ERROR);
            if ($document['identificacion']['ambiente'] !== config('services.factec.environment')) {
                throw ValidationException::withMessages(['api' => 'La invalidación pertenece a otro ambiente.']);
            }
            $this->save($journalId);
            if (in_array($event->estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true)) {
                $this->mirrorResponse($journalId);

                return $this->result($journalId, true);
            }
            $journal = $this->journal($journalId);
            $remote = DB::connection('dtesantacecilia')->table('public.dte_json')->where('dte_id', $journal->codigo_generacion)->sole();
            if (! empty($remote->respuesta_invalid)) {
                $raw = $remote->respuesta_invalid;
                if ($event->respuesta_api === null || ! hash_equals($event->respuesta_api, $raw)) {
                    $this->record($journalId, $raw, FactEcResponse::interpret($raw, 200, $event->codigo_generacion, $document['identificacion']['ambiente']));
                }
                $this->mirrorResponse($journalId);

                return $this->result($journalId, true);
            }
            if ($remote->invalidado && $event->estado === 'PENDIENTE') {
                $this->record($journalId, '', ['state' => 'REVISION', 'seal' => null, 'message' => 'El almacén indica invalidado, pero no hay una respuesta verificable. Requiere conciliación.']);
            }
            $event = $this->event($journalId);
            if ($event->estado !== 'PENDIENTE') {
                return $this->result($journalId, true);
            }
            $this->ensureEligible($journal, json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR), $document['identificacion']['fecEmi']);
            $this->client->ensureTransmissionEnabled();
            $token = $this->client->authenticate();
            DB::table('libreria.venta_invalidaciones')->where('id', $event->id)->update(['estado' => 'ENVIANDO', 'intento_at' => now(), 'updated_at' => now()]);
            try {
                $response = $this->client->invalidate($document, $token);
                $raw = $response->body();
                $result = FactEcResponse::interpret($raw, $response->status(), $event->codigo_generacion, $document['identificacion']['ambiente']);
            } catch (Throwable $error) {
                report($error);
                $raw = '';
                $result = ['state' => 'REVISION', 'seal' => null, 'message' => 'No se recibió confirmación de la invalidación. Consulta la respuesta; no se reenviará automáticamente.'];
            }
            $this->record($journalId, $raw, $result);
            $this->mirrorResponse($journalId);

            return $this->result($journalId, true);
        });
    }

    /** @return array<string, mixed> */
    public function result(int $journalId, bool $apply = false): array
    {
        $event = $this->event($journalId);
        if ($apply && in_array($event->estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true) && $event->aplicada_at === null) {
            try {
                $this->reversal->apply($journalId);
            } catch (Throwable $error) {
                report($error);
                DB::table('libreria.venta_invalidaciones')->where('id', $event->id)->update(['error' => 'Invalidación aceptada; queda pendiente revertir la venta, el inventario o los pagos. Consulta nuevamente para conciliar.', 'updated_at' => now()]);
            }
            $event = $this->event($journalId);
        }
        $message = $event->aplicada_at !== null ? 'Invalidación confirmada y venta anulada. Se devolvieron las existencias y se liberaron las cuotas correspondientes.'
            : ($event->error ?? match ($event->estado) {
                'PENDIENTE' => 'Evento de invalidación preparado. Revisa el JSON y confirma su transmisión.',
                'ENVIANDO' => 'Hay una invalidación en curso o interrumpida. Consulta su respuesta; no se reenviará automáticamente.',
                default => 'Consulta la respuesta de la invalidación.',
            });

        return [
            'id' => $journalId,
            'invalidationState' => $event->estado,
            'uuid' => $event->codigo_generacion,
            'saved' => $event->guardado_externo_at !== null,
            'applied' => $event->aplicada_at !== null,
            'seal' => $event->sello_recepcion,
            'message' => $message,
            'transmitUrl' => route('libreria.ventas.invalidacion.transmit', $journalId),
            'downloadUrl' => route('libreria.ventas.invalidacion.download', $journalId),
        ];
    }

    /** @param array<string, mixed> $document */
    private function ensureEligible(stdClass $journal, array $document, ?string $eventDate = null): void
    {
        if (config('receipts.enabled')) {
            $this->credits->assertNoReceipts($journal->codigo_generacion);
        }
        $identity = $document['identificacion'];
        $expectedState = $identity['ambiente'] === '00' ? 'ACEPTADO_PRUEBA' : 'ACEPTADO';
        if ($identity['ambiente'] !== config('services.factec.environment') || $journal->transmision_estado !== $expectedState || strlen((string) $journal->sello_recepcion) !== 40) {
            throw ValidationException::withMessages(['api' => 'Solo se puede invalidar un DTE aceptado con sello, del ambiente activo. Los documentos en revisión deben conciliarse primero.']);
        }
        if (! in_array([$identity['tipoDte'], $identity['version']], [['01', 2], ['03', 4]], true)) {
            throw ValidationException::withMessages(['api' => 'Este flujo admite Factura v2 y Crédito fiscal v4.']);
        }
        if ($identity['tipoDte'] === '01') {
            $eventDay = $eventDate === null ? CarbonImmutable::now('America/El_Salvador')->startOfDay() : CarbonImmutable::parse($eventDate, 'America/El_Salvador')->startOfDay();
            $issued = CarbonImmutable::parse($identity['fecEmi'], 'America/El_Salvador')->startOfDay();
            if ($eventDay->isBefore($issued) || $eventDay->isAfter($issued->addMonthsNoOverflow(3))) {
                throw ValidationException::withMessages(['api' => 'La fecha del evento está fuera de los tres meses permitidos para generar la invalidación de esta factura.']);
            }
        }
    }

    /** @param array<string, mixed> $document
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function build(stdClass $journal, array $document, array $data, string $uuid, ?string $replacement): array
    {
        if (! preg_match('/^DTE-(01|03)-([MBSP][0-9]{3})(P[0-9]{3})-[0-9]{15}$/D', $journal->numero_control, $codes)) {
            throw ValidationException::withMessages(['api' => 'El número de control original no contiene códigos de establecimiento y punto de venta válidos.']);
        }
        $emitter = $document['emisor'];
        $receiver = $document['receptor'];
        $identity = $document['identificacion'];

        return [
            'identificacion' => [
                'version' => 3,
                'ambiente' => $identity['ambiente'],
                'codigoGeneracion' => $uuid,
                'fecEmi' => $identity['tipoDte'] === '03' ? $identity['fecEmi'] : now('America/El_Salvador')->toDateString(),
                'horEmi' => now('America/El_Salvador')->format('H:i:s'),
                'fusion' => null,
            ],
            'emisor' => [
                'nit' => $emitter['nit'],
                'nombre' => $emitter['nombre'],
                'codEstableMH' => $codes[2],
                'codEstable' => $emitter['codEstable'],
                'codPuntoVentaMH' => $codes[3],
                'codPuntoVenta' => $emitter['codPuntoVenta'],
                'telefono' => $emitter['telefono'],
                'correo' => $emitter['correo'],
            ],
            'documento' => [
                'tipoDte' => $identity['tipoDte'],
                'codigoGeneracion' => $identity['codigoGeneracion'],
                'selloRecibido' => $journal->sello_recepcion,
                'numeroControl' => $journal->numero_control,
                'fecEmi' => $identity['fecEmi'],
                'codigoGeneracionR' => $replacement,
                'tipoDocumento' => $identity['tipoDte'] === '03' ? '36' : ($receiver['tipoDocumento'] ?? null),
                'numDocumento' => $identity['tipoDte'] === '03' ? $receiver['nit'] : ($receiver['numDocumento'] ?? null),
                'nombre' => $receiver['nombre'] ?? null,
                'telefono' => $receiver['telefono'] ?? null,
                'correo' => $receiver['correo'] ?? null,
            ],
            'motivo' => [
                'tipoAnulacion' => (int) $data['reasonType'],
                'motivoAnulacion' => $data['reason'],
                'nombreResponsable' => $data['responsibleName'],
                'tipDocResponsable' => $data['responsibleType'],
                'numDocResponsable' => $data['responsibleDocument'],
                'nombreSolicita' => $data['requesterName'],
                'tipDocSolicita' => $data['requesterType'],
                'numDocSolicita' => $data['requesterDocument'],
            ],
        ];
    }

    /** @param array{state: string, seal: ?string, message: string} $result */
    private function record(int $journalId, string $raw, array $result): void
    {
        $event = $this->event($journalId);
        $document = json_decode($event->documento, true, 512, JSON_THROW_ON_ERROR);
        $state = $result['state'] === 'ACEPTADO' && $document['identificacion']['ambiente'] === '00' ? 'ACEPTADO_PRUEBA' : $result['state'];
        DB::table('libreria.venta_invalidaciones')->where('id', $event->id)->update([
            'estado' => $state,
            'respuesta_api' => $raw === '' ? null : $raw,
            'sello_recepcion' => $result['seal'],
            'error' => $result['state'] === 'ACEPTADO' ? null : $result['message'],
            'confirmada_at' => $result['state'] === 'ACEPTADO' ? now() : null,
            'updated_at' => now(),
        ]);
    }

    private function mirrorResponse(int $journalId): void
    {
        $event = $this->event($journalId);
        $journal = $this->journal($journalId);
        $data = [];
        if ($event->respuesta_api !== null) {
            $data['respuesta_invalid'] = $event->respuesta_api;
        }
        if (in_array($event->estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true)) {
            $data['invalidado'] = true;
        }
        if ($data !== []) {
            DB::connection('dtesantacecilia')->table('public.dte_json')->where('dte_id', $journal->codigo_generacion)->update($data);
        }
    }

    private function event(int $journalId): stdClass
    {
        return DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $journalId)->first() ?? throw ValidationException::withMessages(['api' => 'Primero prepara la solicitud de invalidación.']);
    }

    private function journal(int $journalId): stdClass
    {
        return DB::table('libreria.venta_dtes')->where('id', $journalId)->first() ?? throw new RuntimeException('DTE no encontrado.');
    }
}
