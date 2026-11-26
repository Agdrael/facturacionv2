<?php

namespace App\Services\Libreria;

use App\Models\Libreria\CashSession;
use App\Models\Libreria\Receipt;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class CashSnapshotService
{
    public function __construct(private CashMovementCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function build(CashSession $session): array
    {
        $rows = DB::table('libreria.ventas as v')->join('libreria.venta_dtes as d', 'd.venta_id', '=', 'v.id')
            ->leftJoin('public.users as u', 'u.id', '=', 'd.user_id')
            ->where('v.caja_sesion_id', $session->id)->where('v.estado', '!=', 'ANULADO')->orderBy('v.id')
            ->get(['v.id', 'v.total', 'v.operacion', 'v.tipo_documento', 'd.numero_control', 'd.codigo_generacion', 'd.pagos', 'd.cliente', 'd.transmision_estado', 'd.user_id', 'u.name']);
        $methods = [];
        $documents = ['01' => '0.00', '03' => '0.00'];
        $total = BigDecimal::zero();
        $receivable = BigDecimal::zero();
        $lines = [];
        foreach ($rows as $row) {
            $payments = json_decode($row->pagos, true, 512, JSON_THROW_ON_ERROR);
            $client = json_decode($row->cliente, true, 512, JSON_THROW_ON_ERROR);
            $calculation = $this->calculator->native($payments['received'], (string) ($payments['change'] ?? '0'), (string) $row->total, (string) $row->operacion);
            $received = $calculation['payments'];
            $change = $calculation['change'];
            foreach ($received as $method => $amount) {
                $methods[$method] = (string) BigDecimal::of($methods[$method] ?? '0')->plus($amount)->toScale(2, RoundingMode::HalfUp);
            }
            $amount = BigDecimal::of((string) $row->total);
            $balance = BigDecimal::of($calculation['receivable']);
            $receivable = $receivable->plus($balance);
            $total = $total->plus($amount);
            $documents[$row->tipo_documento] = (string) BigDecimal::of($documents[$row->tipo_documento] ?? '0')->plus($amount)->toScale(2, RoundingMode::HalfUp);
            $lines[] = ['saleId' => (int) $row->id, 'number' => $row->numero_control, 'uuid' => $row->codigo_generacion, 'type' => $row->tipo_documento,
                'client' => $client['name'] ?? '', 'operatorId' => (int) $row->user_id, 'operator' => $row->name ?? 'Usuario eliminado',
                'total' => (string) $amount->toScale(2, RoundingMode::HalfUp), 'payments' => $received, 'change' => (string) $change,
                'receivable' => (string) $balance->toScale(2, RoundingMode::HalfUp), 'dteState' => $row->transmision_estado,
                'references' => array_values(array_filter(array_map(fn (array $payment): ?string => empty($payment['reference']) ? null : (string) $payment['reference'], $payments['received'])))];
        }
        $receiptRows = [];
        $receiptTotal = 0;
        $receiptCategories = [];
        if (config('receipts.enabled')) {
            $receipts = Receipt::query()->where('cash_session_id', $session->id)->where('status', '!=', 'ANULADO')->orderBy('id')->get();
            $payments = DB::table('libreria.receipt_payments')->whereIn('receipt_id', $receipts->modelKeys())->get()->groupBy('receipt_id');
            $details = DB::table('libreria.receipt_lines')->whereIn('receipt_id', $receipts->modelKeys())->get()->groupBy('receipt_id');
            foreach ($receipts as $receipt) {
                $received = [];
                $references = [];
                foreach ($payments->get($receipt->id, collect()) as $payment) {
                    $received[] = ['method' => (string) $payment->method, 'amount' => (string) $payment->amount];
                    if ($payment->reference) {
                        $references[] = $payment->reference;
                    }
                }
                $net = $this->calculator->native($received, $receipt->getRawOriginal('change'), $receipt->getRawOriginal('total'))['payments'];
                foreach ($net as $method => $value) {
                    $methods[$method] = (string) BigDecimal::of($methods[$method] ?? '0')->plus($value)->toScale(2);
                }
                $kinds = [];
                foreach ($details->get($receipt->id, collect()) as $detail) {
                    $receiptCategories[$detail->kind] = ($receiptCategories[$detail->kind] ?? 0) + ReceiptMoney::cents($detail->amount);
                    $kinds[] = $detail->kind;
                }
                $receiptTotal += ReceiptMoney::cents($receipt->total);
                $receiptRows[] = ['receiptId' => $receipt->id, 'number' => $receipt->number(), 'client' => $receipt->recipient_name, 'operator' => $receipt->operator_name,
                    'state' => $receipt->status, 'total' => $receipt->total, 'payments' => $net, 'references' => $references, 'kinds' => array_values(array_unique($kinds))];
            }
        }
        ksort($methods);

        return ['version' => config('receipts.enabled') ? 2 : 1, 'sessionId' => (int) $session->id, 'register' => $session->register->codigo,
            'date' => $session->fecha_operativa->toDateString(), 'openedAt' => $session->abierto_at->setTimezone(config('cash.timezone'))->toIso8601String(),
            'closedAt' => $session->cerrado_at->setTimezone(config('cash.timezone'))->toIso8601String(),
            'openedBy' => $session->abierto_nombre, 'closedBy' => $session->cerrado_nombre,
            'total' => (string) $total->toScale(2, RoundingMode::HalfUp), 'receivable' => (string) $receivable->toScale(2, RoundingMode::HalfUp),
            'methods' => $methods, 'documents' => $documents, 'sales' => $lines,
            'receipts' => $receiptRows, 'receiptTotal' => ReceiptMoney::decimal($receiptTotal), 'receiptCategories' => array_map(ReceiptMoney::decimal(...), $receiptCategories),
            'collections' => (string) array_reduce($methods, fn (BigDecimal $sum, $value) => $sum->plus($value), BigDecimal::zero())->toScale(2)];
    }
}
