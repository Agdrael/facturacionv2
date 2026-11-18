<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Http\Requests\Libreria\InvalidateSaleRequest;
use App\Models\Dte\Cat022;
use App\Models\Dte\Cat024;
use App\Services\Dte\DteInvalidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class SaleInvalidationController extends Controller
{
    public function show(Request $request, int $journal, DteInvalidationService $invalidations): JsonResponse
    {
        $row = $this->authorized($request, $journal);
        $event = DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $journal)->first();
        $document = json_decode($row->documento, true, 512, JSON_THROW_ON_ERROR);

        return response()->json(['number' => $row->numero_control, 'environment' => $document['identificacion']['ambiente'],
            'storeUrl' => route('libreria.ventas.invalidacion.store', $journal),
            'event' => $event === null ? null : $invalidations->result($journal),
            'reasonTypes' => Cat024::catalogOptions()->whereInStrict('codigo', ['1', '2', '3'])->values()->all(),
            'identityDocuments' => Cat022::catalogOptions()->all(),
            'defaults' => ['reasonType' => '2', 'reason' => '', 'replacement' => null, 'responsibleName' => $request->user()?->name,
                'responsibleType' => '13', 'responsibleDocument' => '', 'requesterName' => $document['receptor']['nombre'] ?? '',
                'requesterType' => $document['receptor']['tipoDocumento'] ?? '13', 'requesterDocument' => $document['receptor']['numDocumento'] ?? '', 'confirmed' => false]]);
    }

    public function store(InvalidateSaleRequest $request, int $journal, DteInvalidationService $invalidations): JsonResponse
    {
        $invalidations->prepare($journal, $request->validated(), (int) $request->user()?->getAuthIdentifier());
        $saved = true;
        try {
            $invalidations->save($journal);
        } catch (Throwable $error) {
            report($error);
            $saved = false;
        }

        return response()->json([...$invalidations->result($journal), ...($saved ? [] : ['saved' => false, 'message' => 'Evento guardado en Laravel. Falta copiarlo a dte_json; al transmitir se reintentará el guardado sin duplicar la solicitud.'])], $saved ? 201 : 202);
    }

    public function transmit(Request $request, int $journal, DteInvalidationService $invalidations): JsonResponse
    {
        $this->authorized($request, $journal);

        try {
            return response()->json($invalidations->transmit($journal));
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            return response()->json(['message' => 'No se pudo completar la operación. Consulta el estado de la invalidación antes de volver a intentar.'], 503);
        }
    }

    public function download(Request $request, int $journal): Response
    {
        $row = $this->authorized($request, $journal);
        $event = DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $journal)->first();
        abort_if($event === null, 404);

        return response($event->documento, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$row->numero_control.'-invalidacion.json"']);
    }

    private function authorized(Request $request, int $journal): stdClass
    {
        $row = DB::table('libreria.venta_dtes')->where('id', $journal)->first();
        abort_if($row === null, 404);
        Gate::forUser($request->user())->authorize('libreria.ventas.invalidar');

        return $row;
    }
}
