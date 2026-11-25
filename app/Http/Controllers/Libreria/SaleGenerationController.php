<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Http\Requests\Libreria\StoreSaleRequest;
use App\Models\User;
use App\Services\Dte\DteStorageService;
use App\Services\Dte\DteTransmissionService;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\SaleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class SaleGenerationController extends Controller
{
    public function quote(StoreSaleRequest $request, SaleService $sales, CashRegisterService $cash): JsonResponse
    {
        DB::transaction(fn () => $cash->saleSession($request->user()));
        $quote = $sales->quote($request->validated());

        return response()->json(['total' => $quote['total']]);
    }

    public function store(StoreSaleRequest $request, SaleService $sales, DteStorageService $storage, DteTransmissionService $transmitter): JsonResponse
    {
        Gate::authorize('libreria.ventas.transmitir');
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $id = $sales->generate($request->validated(), $user);

        return $this->saveResponse($id, $storage, $transmitter);
    }

    public function retry(Request $request, int $journal, DteStorageService $storage, DteTransmissionService $transmitter): JsonResponse
    {
        $this->owned($request, $journal);
        Gate::authorize('libreria.ventas.transmitir');

        return $this->saveResponse($journal, $storage, $transmitter);
    }

    public function transmit(Request $request, int $journal, DteTransmissionService $transmitter): JsonResponse
    {
        $this->owned($request, $journal);

        return response()->json(['id' => $journal, ...$transmitter->transmit($journal)]);
    }

    public function download(Request $request, int $journal): Response
    {
        $row = $this->owned($request, $journal);

        return response($row->documento, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$row->numero_control.'.json"']);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $canPrintOthers = $user->hasRole(['admin', 'contador']);
        $canTransmit = $request->user()->can('libreria.ventas.transmitir');
        $canRetry = $canTransmit && $request->user()->can('libreria.ventas.reintentar');
        $canDownload = $request->user()->can('libreria.ventas.descargar');
        $rows = DB::table('libreria.venta_dtes as dte')->join('libreria.ventas as venta', 'venta.id', '=', 'dte.venta_id')
            ->leftJoin('libreria.venta_invalidaciones as invalidacion', 'invalidacion.venta_dte_id', '=', 'dte.id')
            ->join('public.users as usuario', 'usuario.id', '=', 'dte.user_id')->orderByDesc('dte.id')->limit(50)
            ->get(['dte.id', 'dte.user_id', 'dte.numero_control', 'dte.cliente', 'dte.guardado_externo_at', 'dte.created_at', 'dte.transmision_estado', 'dte.sello_recepcion', 'venta.total', 'venta.estado', 'usuario.name', 'invalidacion.estado as invalidacion_estado', 'invalidacion.aplicada_at']);

        return response()->json(['data' => $rows->map(fn (object $row): array => ['id' => $row->numero_control,
            'time' => CarbonImmutable::parse($row->created_at)->setTimezone('America/El_Salvador')->format('H:i'), 'client' => json_decode($row->cliente, true, 512, JSON_THROW_ON_ERROR)['name'],
            'total' => (int) round((float) $row->total * 100), 'status' => $row->aplicada_at !== null ? 'Anulada' : ($row->invalidacion_estado !== null ? 'Invalidación: '.$row->invalidacion_estado : ($row->guardado_externo_at === null ? 'Guardado pendiente' : $this->transmissionLabel($row->transmision_estado))),
            'operator' => $row->name, 'date' => CarbonImmutable::parse($row->created_at)->setTimezone('America/El_Salvador')->toDateString(),
            'ticketUrl' => route('libreria.ventas.ticket', $row->id),
            'canPrint' => $canPrintOthers || (int) $row->user_id === (int) $user->getAuthIdentifier(),
            'canManage' => (int) $row->user_id === (int) $request->user()?->getAuthIdentifier() && ($canRetry || $canTransmit || $canDownload),
            'canRetry' => $canRetry && (int) $row->user_id === (int) $request->user()?->getAuthIdentifier(),
            'canTransmit' => $canTransmit && (int) $row->user_id === (int) $request->user()?->getAuthIdentifier(),
            'canDownload' => $canDownload && (int) $row->user_id === (int) $request->user()?->getAuthIdentifier(),
            'retryUrl' => route('libreria.ventas.retry', $row->id), 'downloadUrl' => route('libreria.ventas.download', $row->id), 'pending' => $row->guardado_externo_at === null,
            'transmitUrl' => route('libreria.ventas.transmit', $row->id), 'transmissionState' => $row->transmision_estado, 'seal' => $row->sello_recepcion,
            'invalidationState' => $row->invalidacion_estado, 'invalidationUrl' => route('libreria.ventas.invalidacion.show', $row->id),
            'canInvalidate' => Gate::forUser($request->user())->allows('libreria.ventas.invalidar') && in_array($row->transmision_estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true)])->all()]);
    }

    private function owned(Request $request, int $id): stdClass
    {
        $row = DB::table('libreria.venta_dtes')->where('id', $id)->first();
        abort_if($row === null, 404);
        abort_unless((int) $row->user_id === (int) $request->user()?->getAuthIdentifier(), 403);

        return $row;
    }

    private function saveResponse(int $id, DteStorageService $storage, DteTransmissionService $transmitter): JsonResponse
    {
        $transmissionMessage = null;
        try {
            $storage->save($id);
            if (config('services.factec.transmission_enabled')) {
                $transmissionMessage = $transmitter->transmit($id)['message'];
            }
        } catch (ValidationException $error) {
            $transmissionMessage = 'DTE registrado. No se completó la transmisión: '.collect($error->errors())->flatten()->implode(' ');
        } catch (Throwable $error) {
            report($error);
            $transmissionMessage = 'DTE registrado. No se pudo confirmar la transmisión; revisa su estado en el historial sin repetir la venta.';
        }
        $row = DB::table('libreria.venta_dtes')->where('id', $id)->first();
        abort_if($row === null, 404);
        $invalidation = DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $id)->first(['estado', 'aplicada_at']);

        return response()->json(['id' => $id, 'number' => $row->numero_control, 'uuid' => $row->codigo_generacion,
            'ticketUrl' => route('libreria.ventas.ticket', $id),
            'saved' => $row->guardado_externo_at !== null, 'status' => $invalidation?->aplicada_at !== null ? 'ANULADO' : 'GENERADO',
            'invalidationState' => $invalidation?->estado,
            'transmitUrl' => route('libreria.ventas.transmit', $id), 'transmissionState' => $row->transmision_estado, 'seal' => $row->sello_recepcion,
            'retryUrl' => route('libreria.ventas.retry', $id), 'downloadUrl' => route('libreria.ventas.download', $id),
            'message' => $invalidation !== null ? 'Este DTE tiene una solicitud de invalidación. Consulta su estado desde el historial.' : ($row->guardado_externo_at === null ? 'DTE registrado. Falta completar el guardado; puedes recuperarlo desde el historial sin duplicar la venta.' : ($transmissionMessage ?? (config('services.factec.transmission_enabled') ? 'DTE registrado. Consulta el estado de transmisión en el historial.' : 'DTE registrado. La transmisión está deshabilitada; revisa la configuración de FactEC.')))], $row->guardado_externo_at === null ? 202 : 201);
    }

    private function transmissionLabel(string $state): string
    {
        return match ($state) {
            'ACEPTADO_PRUEBA' => 'Aceptado (pruebas)', 'ACEPTADO' => 'Aceptado', 'RECHAZADO' => 'Rechazado',
            'REVISION' => 'Requiere revisión', 'ENVIANDO' => 'En transmisión', default => 'Generado',
        };
    }
}
