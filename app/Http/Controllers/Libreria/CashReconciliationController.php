<?php

namespace App\Http\Controllers\Libreria;

use App\Http\Controllers\Controller;
use App\Models\Libreria\CashReconciliation;
use App\Models\Libreria\Receipt;
use App\Services\Libreria\CashRegisterService;
use App\Services\Libreria\CashSummaryService;
use App\Services\Libreria\CashWorkbookService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $nativeSales = array_filter($record->snapshot['sales'], fn (array $row): bool => ($row['source'] ?? 'v2') === 'v2');
        $ids = array_column($nativeSales, 'saleId');
        $incidents = DB::table('libreria.venta_dtes as d')->join('libreria.venta_invalidaciones as i', 'i.venta_dte_id', '=', 'd.id')
            ->whereIn('d.venta_id', $ids)->whereNotNull('i.aplicada_at')->get(['d.numero_control', 'i.aplicada_at']);

        if (! empty($record->snapshot['receipts'])) {
            $nativeReceipts = array_filter($record->snapshot['receipts'], fn (array $row): bool => ($row['source'] ?? 'v2') === 'v2');
            $receiptIncidents = ($nativeReceipts === [] ? collect() : Receipt::query()->whereIn('id', array_column($nativeReceipts, 'receiptId'))->whereNotNull('annulled_at')->get())
                ->map(fn ($receipt) => (object) ['numero_control' => $receipt->number(), 'aplicada_at' => $receipt->annulled_at]);
            $incidents = $incidents->concat($receiptIncidents);
        }

        if (isset($record->snapshot['legacy'])) {
            foreach (['sales' => ['public.ventas', 'id_venta', 'legacySaleId'], 'receipts' => ['public.recibos', 'id', 'legacyReceiptId']] as $key => [$table, $id, $field]) {
                $rows = array_values(array_filter($record->snapshot[$key], fn (array $row): bool => ($row['source'] ?? 'v2') === 'v1'));
                $current = DB::table($table)->whereIn($id, array_column($rows, $field))->get()->keyBy($id);
                foreach ($rows as $row) {
                    $live = $current->get($row[$field]);
                    if ($live === null || $live->anulado) {
                        $incidents->push((object) ['numero_control' => $row['number'].' · V1: '.($live === null ? 'registro eliminado' : 'anulado posteriormente'), 'aplicada_at' => null]);
                    }
                }
            }
        }

        return view('libreria.cajas.arqueo', ['record' => $record, 'incidents' => $incidents]);
    }

    public function summary(Request $request, int $reconciliation, string $kind, CashSummaryService $reports): Response
    {
        $record = $this->authorized($request, $reconciliation, 'descargar');
        abort_unless($record->session->cerrado_at !== null, 422, 'Primero debe cerrarse la caja.');
        $filename = 'Resumen_'.$kind.'_'.$record->session->register->codigo.'_sesion_'.$record->sesion_id.'.pdf';

        return response($reports->render($record, $kind), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$filename.'"', 'Cache-Control' => 'private, no-store',
        ]);
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

    public function refreshTemplate(Request $request, int $reconciliation, CashWorkbookService $files): RedirectResponse
    {
        $record = $this->authorized($request, $reconciliation, 'cargar');
        $updated = $files->refreshTemplate($record, $request->user());

        return back()->with('status', $updated
            ? 'Plantilla actualizada. Se conservaron los archivos anteriores y los datos manuales ya entregados. Descarga el original actualizado y vuelve a entregar el Excel para revisión.'
            : 'El original ya utiliza la plantilla actual. Puedes descargarlo.');
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
