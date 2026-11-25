<?php

namespace App\Services\Libreria;

use App\Models\Libreria\CashReconciliation;
use Carbon\CarbonImmutable;
use TCPDF;

class CashSummaryService
{
    /** @return array<string, mixed> */
    public function summary(CashReconciliation $record, string $kind): array
    {
        abort_unless(in_array($kind, ['ventas', 'recibos'], true), 404);
        $snapshot = $record->snapshot;
        $key = $kind === 'ventas' ? 'sales' : 'receipts';
        abort_unless(isset($snapshot[$key]) && is_array($snapshot[$key]), 422, 'Este cierre no conserva los datos necesarios para este resumen.');
        $rows = $snapshot[$key];
        $methods = [];
        $operators = [];
        $states = [];
        foreach ($rows as $row) {
            foreach ($row['payments'] as $code => $amount) {
                $methods[$code] = ($methods[$code] ?? 0) + ReceiptMoney::cents($amount);
            }
            $operators[] = $row['operator'];
            $state = $row[$kind === 'ventas' ? 'dteState' : 'state'];
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        ksort($methods);
        ksort($states);
        $collected = array_sum($methods);
        $credit = $kind === 'ventas' ? ReceiptMoney::cents($snapshot['receivable']) : 0;
        $total = ReceiptMoney::cents($snapshot[$kind === 'ventas' ? 'total' : 'receiptTotal']);
        abort_unless($collected + $credit === $total, 422, 'Los importes guardados en este cierre requieren revisión antes de generar el resumen.');
        $labels = ['01' => 'Efectivo', '02' => 'Tarjeta débito', '03' => 'Tarjeta crédito', '04' => 'Cheque', '05' => 'Transferencia'];
        $payments = [];
        foreach ($methods as $code => $amount) {
            if ($amount !== 0) {
                $payments[] = ['code' => (string) $code, 'name' => $labels[$code] ?? 'Medio '.$code, 'amount' => ReceiptMoney::decimal($amount)];
            }
        }
        $stateLabels = ['ACEPTADO' => 'Aceptado', 'PENDIENTE' => 'Pendiente', 'RECHAZADO' => 'Rechazado', 'ERROR' => 'Error',
            'EMITIDO' => 'Emitido', 'PENDIENTE_APLICACION' => 'Pendiente de aplicación', 'REVISION' => 'Requiere revisión', 'ANULACION_PENDIENTE' => 'Anulación pendiente'];
        $statuses = [];
        foreach ($states as $state => $count) {
            $statuses[] = ['name' => $stateLabels[$state] ?? $state, 'count' => $count];
        }

        return ['kind' => $kind, 'title' => $kind === 'ventas' ? 'RESUMEN DE VENTAS' : 'RESUMEN DE RECIBOS',
            'register' => $snapshot['register'], 'sessionId' => $snapshot['sessionId'], 'reconciliationId' => $record->id,
            'date' => CarbonImmutable::parse($snapshot['date'])->format('d/m/Y'),
            'openedAt' => CarbonImmutable::parse($snapshot['openedAt'])->setTimezone(config('cash.timezone'))->format('d/m/Y H:i'),
            'closedAt' => CarbonImmutable::parse($snapshot['closedAt'])->setTimezone(config('cash.timezone'))->format('d/m/Y H:i'),
            'closedBy' => $snapshot['closedBy'], 'operators' => array_values(array_unique($operators)), 'count' => count($rows),
            'methods' => $payments, 'collected' => ReceiptMoney::decimal($collected), 'credit' => ReceiptMoney::decimal($credit),
            'total' => ReceiptMoney::decimal($total), 'statuses' => $statuses];
    }

    public function render(CashReconciliation $record, string $kind): string
    {
        $summary = $this->summary($record, $kind);
        $height = min(400, max(150, 125 + count($summary['methods']) * 7 + count($summary['operators']) * 6
            + count($summary['statuses']) * 5 + ($summary['credit'] !== '0.00' ? 30 : 0)));
        $pdf = new TCPDF('P', 'mm', [80, $height], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(4, 4, 4);
        $pdf->SetAutoPageBreak(true, 5);
        $pdf->SetCreator('Santa Cecilia');
        $pdf->SetTitle($summary['title'].' - '.$summary['register'].' - Sesión '.$summary['sessionId']);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->AddPage();
        $pdf->writeHTML(view('libreria.cajas.resumen', ['summary' => $summary])->render(), true, false, true, false, '');

        return $pdf->Output('', 'S');
    }
}
