<?php

namespace App\Services\Libreria;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class SaleReversalService
{
    public function __construct(private AcademicPaymentService $payments) {}

    public function apply(int $journalId): void
    {
        $event = DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $journalId)->first();
        $journal = DB::table('libreria.venta_dtes')->where('id', $journalId)->first();
        if ($event === null || $journal === null || ! in_array($event->estado, ['ACEPTADO', 'ACEPTADO_PRUEBA'], true) || empty($event->sello_recepcion)) {
            throw new RuntimeException('La reversión requiere una invalidación aceptada con sello.');
        }
        if ($event->aplicada_at !== null) {
            return;
        }
        $this->payments->reverse($journal);
        DB::transaction(function () use ($journal, $journalId): void {
            $point = DB::table('libreria.ventas')->where('id', $journal->venta_id)->value('caja');
            app(CashRegisterService::class)->lockRegister((string) $point);
            $event = DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $journalId)->lockForUpdate()->first();
            if ($event->aplicada_at !== null) {
                return;
            }
            $inventoryId = $this->returnInventory($journal, $event);
            DB::table('libreria.ventas')->where('id', $journal->venta_id)->update(['estado' => 'ANULADO', 'updated_at' => now()]);
            DB::table('libreria.venta_cuotas')->where('venta_id', $journal->venta_id)->update(['reserva_activa' => null, 'liberada_at' => now()]);
            DB::table('libreria.venta_invalidaciones')->where('id', $event->id)->update(['inventario_doc_id' => $inventoryId, 'aplicada_at' => now(), 'error' => null, 'updated_at' => now()]);
        });
    }

    private function returnInventory(stdClass $journal, stdClass $event): ?int
    {
        if ($journal->inventario_doc_id === null) {
            return null;
        }
        $original = DB::table('inv.inv_documentos')->where('doc_id', $journal->inventario_doc_id)->first();
        $lines = DB::table('inv.inv_documentos_det')->where('doc_id', $journal->inventario_doc_id)->orderBy('producto_id')->orderBy('propiedad')->get();
        if ($original === null || $lines->isEmpty() || $original->tipo_mov !== 'VENTA' || strcasecmp((string) $original->doc_numero, (string) $journal->codigo_generacion) !== 0) {
            throw new RuntimeException('No se encontró el documento original de inventario o sus detalles de venta.');
        }
        $movement = DB::table('inv.inv_cat_tipo_mov')->where('tipo_mov', 'AJUSTE_POS')->first();
        if ($movement === null || ! $movement->afecta_existencia || $movement->afecta_cpp_global || ! DB::table('inv.inv_cat_doc_tipo')->where('doc_tipo', 'AJP')->exists()) {
            throw new RuntimeException('Configura AJP / AJUSTE_POS para devolver existencias sin alterar el costo promedio.');
        }
        DB::table('public.productos')->whereIn('id_producto', $lines->pluck('producto_id')->all())->orderBy('id_producto')->lockForUpdate()->get(['id_producto']);
        $id = DB::table('inv.inv_documentos')->insertGetId(['doc_tipo' => 'AJP', 'doc_numero' => $event->codigo_generacion,
            'fecha_doc' => now('America/El_Salvador')->toDateString(), 'bodega_id' => $original->bodega_id, 'tipo_mov' => 'AJUSTE_POS',
            'notas' => 'Invalidación de '.$journal->numero_control.' · evento '.$event->codigo_generacion], 'doc_id');
        foreach ($lines as $line) {
            if ($line->tipo_mov !== 'VENTA' || ! in_array($line->propiedad, ['CONSIGNADO', 'PROPIO'], true) || (float) $line->cantidad <= 0) {
                throw new RuntimeException('El detalle original de inventario no es una salida válida de venta.');
            }
            DB::table('inv.inv_documentos_det')->insert(['doc_id' => $id, 'producto_id' => $line->producto_id, 'bodega_id' => $line->bodega_id,
                'propiedad' => $line->propiedad, 'lote_id' => $line->lote_id, 'tipo_mov' => 'AJUSTE_POS', 'cantidad' => $line->cantidad,
                'precio_unit' => $line->precio_unit, 'notas' => 'Reversión de detalle '.$line->doc_det_id.' · '.$journal->numero_control]);
        }

        return $id;
    }
}
