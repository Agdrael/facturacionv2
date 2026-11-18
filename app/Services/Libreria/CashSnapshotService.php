<?php

namespace App\Services\Libreria;

use App\Models\Libreria\CashSession;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CashSnapshotService
{
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
            $received = [];
            foreach ($payments['received'] as $payment) {
                $method = (string) $payment['method'];
                $received[$method] = BigDecimal::of($received[$method] ?? '0')->plus((string) $payment['amount']);
            }
            $change = BigDecimal::of((string) ($payments['change'] ?? '0'));
            if ($change->isPositive()) {
                $received['01'] = BigDecimal::of($received['01'] ?? '0')->minus($change);
                if ($received['01']->isNegative()) {
                    throw new RuntimeException('El cambio excede el efectivo registrado en '.$row->numero_control);
                }
            }
            $paid = BigDecimal::zero();
            foreach ($received as $method => $amount) {
                $paid = $paid->plus($amount);
                $methods[$method] = (string) BigDecimal::of($methods[$method] ?? '0')->plus($amount)->toScale(2, RoundingMode::HalfUp);
                $received[$method] = (string) $amount->toScale(2, RoundingMode::HalfUp);
            }
            $amount = BigDecimal::of((string) $row->total);
            $balance = (string) $row->operacion === '2' && $amount->isGreaterThan($paid) ? $amount->minus($paid) : BigDecimal::zero();
            $receivable = $receivable->plus($balance);
            $total = $total->plus($amount);
            $documents[$row->tipo_documento] = (string) BigDecimal::of($documents[$row->tipo_documento] ?? '0')->plus($amount)->toScale(2, RoundingMode::HalfUp);
            $lines[] = ['saleId' => (int) $row->id, 'number' => $row->numero_control, 'uuid' => $row->codigo_generacion, 'type' => $row->tipo_documento,
                'client' => $client['name'] ?? '', 'operatorId' => (int) $row->user_id, 'operator' => $row->name ?? 'Usuario eliminado',
                'total' => (string) $amount->toScale(2, RoundingMode::HalfUp), 'payments' => $received, 'change' => (string) $change,
                'receivable' => (string) $balance->toScale(2, RoundingMode::HalfUp), 'dteState' => $row->transmision_estado,
                'references' => array_values(array_filter(array_map(fn (array $payment): ?string => empty($payment['reference']) ? null : (string) $payment['reference'], $payments['received'])))];
        }
        ksort($methods);

        return ['version' => 1, 'sessionId' => (int) $session->id, 'register' => $session->register->codigo,
            'date' => $session->fecha_operativa->toDateString(), 'openedAt' => $session->abierto_at->setTimezone(config('cash.timezone'))->toIso8601String(),
            'closedAt' => $session->cerrado_at->setTimezone(config('cash.timezone'))->toIso8601String(),
            'openedBy' => $session->abierto_nombre, 'closedBy' => $session->cerrado_nombre,
            'total' => (string) $total->toScale(2, RoundingMode::HalfUp), 'receivable' => (string) $receivable->toScale(2, RoundingMode::HalfUp),
            'methods' => $methods, 'documents' => $documents, 'sales' => $lines];
    }
}
