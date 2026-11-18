<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Models\Libreria\CashReconciliation;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\CashWorkbookService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CashReconciliationController extends Controller
{
    private function authorized(Request $request, int $id, string $action): CashReconciliation
    {
        Gate::authorize('libreria.arqueos.'.$action);
        $record = CashReconciliation::query()->with('session.register')->findOrFail($id);
        app(CashRegisterService::class)->assertVisible($request->user(), $record->session->register->codigo, 'libreria.arqueos.ver_todos');

        return $record;
    }

    public function show(Request $request, int $reconciliation): View
    {
        $record = $this->authorized($request, $reconciliation, 'ver');
        $ids = array_column($record->snapshot['sales'], 'saleId');
        $incidents = DB::table('libreria.venta_dtes as d')->join('libreria.venta_invalidaciones as i', 'i.venta_dte_id', '=', 'd.id')
            ->whereIn('d.venta_id', $ids)->whereNotNull('i.aplicada_at')->get(['d.numero_control', 'i.aplicada_at']);

        return view('libreria.cajas.arqueo', ['record' => $record, 'incidents' => $incidents]);
    }

    public function download(Request $request, int $reconciliation, string $kind, CashWorkbookService $files): StreamedResponse
    {
        $record = $this->authorized($request, $reconciliation, 'descargar');
        abort_unless(in_array($kind, ['original', 'entregado', 'firmado'], true), 404);
        $input = $request->validate(['version' => ['nullable', 'integer', 'min:0']]);
        if (isset($input['version'])) {
            $version = ($record->archivos ?? [])[(int) $input['version']] ?? null;
            abort_unless($version !== null && $version['tipo'] === $kind, 404);
            $path = $version['path'];
        } else {
            $path = $kind === 'original' ? $files->original($record, $request->user()) : $record->getAttribute($kind.'_path');
        }
        $disk = Storage::disk(config('cash.disk'));
        abort_unless(is_string($path) && $disk->exists($path), 404, 'El archivo no está disponible.');
        $response = $disk->download($path, 'Arqueo_'.$record->session->register->codigo.'_sesion_'.$record->sesion_id.'_'.$kind.'.xlsx');
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function upload(Request $request, int $reconciliation, CashWorkbookService $files): RedirectResponse
    {
        $record = $this->authorized($request, $reconciliation, 'cargar');
        $request->validate(['archivo' => ['required', 'file', 'mimes:xlsx', 'extensions:xlsx', 'max:10240']]);
        $files->upload($record, $request->file('archivo'), $request->user());

        return back()->with('status', 'Excel entregado. Está pendiente de revisión y firma.');
    }

    public function review(Request $request, int $reconciliation, CashWorkbookService $files): RedirectResponse
    {
        $record = $this->authorized($request, $reconciliation, 'revisar');
        $input = $request->validate(['version' => ['required', 'string', 'size:64']]);
        $files->review($record, $request->user(), $input['version']);

        return back()->with('status', 'Revisión registrada. El arqueo ya puede firmarse.');
    }

    public function sign(Request $request, int $reconciliation, CashWorkbookService $files): RedirectResponse
    {
        $record = $this->authorized($request, $reconciliation, 'firmar');
        $input = $request->validate(['firma' => ['required', 'string', 'max:350000'], 'confirmed' => ['accepted'], 'version' => ['required', 'string', 'size:64']]);
        $files->sign($record, $input['firma'], $request->user(), $input['version']);

        return back()->with('status', 'Arqueo firmado. La copia firmada quedó protegida.');
    }
}
