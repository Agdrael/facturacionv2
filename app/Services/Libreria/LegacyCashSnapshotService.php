<?php

namespace App\Services\Libreria;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class LegacyCashSnapshotService
{
    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['caja' => $message]);
        }
    }

    private function cents(mixed $amount): int
    {
        $this->require($amount !== null, 'Un importe de V1 no está registrado. Requiere conciliación.');

        return ReceiptMoney::cents((string) BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp));
    }

    /** @param Collection<int, stdClass> $turns
     * @return array<string, mixed> */
    public function build(string $register, Collection $turns): array
    {
        $movements = DB::table('libreria.legacy_cash_movements')->whereIn('legacy_session_id', $turns->pluck('legacy_id'))->where('excluded', false)->get();
        $sales = DB::table('public.ventas as v')->leftJoin('public.clientes as c', 'c.id', '=', 'v.id_cliente')
            ->whereIn('v.id_venta', $movements->where('kind', 'venta')->pluck('legacy_id'))
            ->select('v.*', 'c.nombre as client_name')->orderBy('v.id_venta')->get();
        $receipts = DB::table('public.recibos')->whereIn('id', $movements->where('kind', 'recibo')->pluck('legacy_id'))->orderBy('id')->get();
        $this->require($sales->count() + $receipts->count() === $movements->count(), 'Un movimiento V1 fue eliminado. Revisa el corte antes de cerrar.');
        $payments = DB::table('public.metodos_pago_venta')->whereIn('id_venta', $sales->pluck('id_venta'))->get()->groupBy('id_venta');
        $legacyUuids = $sales->pluck('numero_factura')->map(fn ($uuid): string => strtolower(trim((string) $uuid)))->all();
        // Cast the native UUID to text, not historical text to UUID: old references may not be valid UUIDs.
        $nativeUuids = DB::table('libreria.venta_dtes')->whereIn(DB::raw('LOWER(CAST(codigo_generacion AS TEXT))'), $legacyUuids)
            ->pluck('codigo_generacion')->map(fn ($uuid): string => strtolower((string) $uuid))->all();
        $nativeNumbers = DB::table('libreria.venta_dtes')->whereIn('numero_control', $sales->pluck('numero_control'))->pluck('numero_control')->all();
        $mirrors = DB::table('public.libreria_recibo_documentos')->whereIn('legacy_receipt_id', $receipts->pluck('id'))->pluck('legacy_receipt_id')->all();
        $lines = [];
        $seen = [];
        foreach ($sales as $sale) {
            $uuid = strtolower(trim((string) $sale->numero_factura));
            if ($sale->anulado || (string) $sale->operacion === '05' || in_array($uuid, $nativeUuids, true)) {
                continue;
            }
            $this->require(! empty($sale->numero_factura) && in_array((string) $sale->operacion, ['1', '2', '3'], true), 'La venta V1 #'.$sale->id_venta.' no identifica UUID y operación válidos.');
            $this->require(! in_array($sale->numero_control, $nativeNumbers, true), 'El número '.$sale->numero_control.' está duplicado entre V1 y V2 con UUID distintos.');
            $this->require(! isset($seen['uuid:'.$uuid]) && ! isset($seen['number:'.$sale->numero_control]), 'V1 tiene un UUID o número de control duplicado.');
            $seen['uuid:'.$uuid] = $seen['number:'.$sale->numero_control] = true;
            $this->require((bool) preg_match('/^DTE-(01|03)-[A-Z0-9]{4}'.preg_quote($register, '/').'-[0-9]+$/', $sale->numero_control), 'El documento V1 #'.$sale->id_venta.' no identifica una caja y tipo válidos.');
            $total = $this->cents($sale->total);
            $this->require($total >= 0, 'La venta V1 '.$sale->numero_control.' tiene un importe negativo sin anulación.');
            $received = [];
            $change = 0;
            $credit = (string) $sale->operacion === '2';
            // V1 stored full sale values in its payment fields for credit documents.
            // Its actual subsequent collections are represented by separate receipts.
            if (! $credit) {
                $methods = $payments->get($sale->id_venta, collect());
                $this->require($methods->count() === 1, 'La venta V1 '.$sale->numero_control.' no tiene un único registro de pagos.');
                $payment = $methods->first();
                $allowed = array_map('trim', explode(',', $payment->forma_pago ?? ''));
                foreach (['efectivo' => '01', 'tarjetad' => '02', 'tarjetac' => '03', 'cheque' => '04', 'remesa' => '05', 'bitcoin' => '11', 'linea' => '99', 'qr' => 'V1_CARD'] as $column => $code) {
                    $amount = $this->cents($payment->{$column} ?? '0');
                    $this->require($amount >= 0, 'Un pago V1 tiene importe negativo.');
                    if ($amount === 0) {
                        continue;
                    }
                    $this->require(in_array($code, $allowed, true) || ($code === 'V1_CARD' && (in_array('02', $allowed, true) || in_array('03', $allowed, true))),
                        'Los métodos e importes de pago de '.$sale->numero_control.' no coinciden.');
                    $received[$code] = ($received[$code] ?? 0) + $amount;
                }
                $change = max(0, array_sum($received) - $total);
                $this->require(($received['01'] ?? 0) >= $change, 'El exceso de pago de '.$sale->numero_control.' no puede conciliarse como cambio en efectivo.');
                if ($change > 0) {
                    $received['01'] = ($received['01'] ?? 0) - $change;
                }
                $this->require(array_sum($received) === $total, 'Los pagos de '.$sale->numero_control.' no cubren su total. Requiere conciliación en V1.');
            }
            $movement = $movements->where('kind', 'venta')->firstWhere('legacy_id', $sale->id_venta);
            $lines[] = ['source' => 'v1', 'saleId' => null, 'legacySaleId' => (int) $sale->id_venta, 'legacySessionId' => (int) $movement->legacy_session_id,
                'number' => $sale->numero_control, 'uuid' => $sale->numero_factura, 'type' => explode('-', $sale->numero_control)[1],
                'client' => $sale->client_name ?? 'Sin cliente registrado en V1', 'operatorId' => null, 'legacyOperatorId' => $sale->id_usuario,
                'operator' => 'Operador V1 #'.$sale->id_usuario, 'total' => ReceiptMoney::decimal($total),
                'payments' => array_map(ReceiptMoney::decimal(...), $received), 'change' => ReceiptMoney::decimal($change),
                'receivable' => ReceiptMoney::decimal($credit ? $total : 0), 'dteState' => empty($sale->sello_recepcion) ? 'PENDIENTE' : 'ACEPTADO',
                'references' => []];
        }
        $receiptLines = [];
        foreach ($receipts as $receipt) {
            if ($receipt->anulado || in_array($receipt->id, $mirrors)) {
                continue;
            }
            $movement = $movements->where('kind', 'recibo')->firstWhere('legacy_id', $receipt->id);
            $total = $this->cents($receipt->precio ?? $movement->confirmed_amount);
            $this->require($receipt->precio === null || $movement->confirmed_amount === null || $this->cents($receipt->precio) === $this->cents($movement->confirmed_amount),
                'El importe actual del recibo V1 #'.$receipt->id.' contradice el confirmado. Requiere revisión.');
            $this->require($total > 0, 'El recibo V1 #'.$receipt->id.' no tiene un importe positivo conciliado.');
            $method = $receipt->tarjeta === null ? $movement->confirmed_method : ($receipt->tarjeta ? 'V1_CARD' : '01');
            $this->require(in_array($method, ['01', 'V1_CARD'], true), 'El recibo V1 #'.$receipt->id.' no identifica si se pagó en efectivo o tarjeta.');
            $this->require($movement->confirmed_method === null || $movement->confirmed_method === $method, 'El medio de pago del recibo V1 cambió después de su conciliación.');
            $receiptLines[] = ['source' => 'v1', 'receiptId' => null, 'legacyReceiptId' => (int) $receipt->id, 'legacySessionId' => (int) $movement->legacy_session_id,
                'number' => 'V1 #'.$receipt->id, 'client' => $receipt->nombre_cliente, 'operator' => 'No registrado en V1', 'state' => 'EMITIDO',
                'total' => ReceiptMoney::decimal($total), 'payments' => [$method => ReceiptMoney::decimal($total)],
                'references' => [], 'kinds' => ['legacy'], 'concept' => $receipt->codigo_recibo, 'creditAccount' => $receipt->cuenta_abono, 'debitAccount' => $receipt->cuenta_cargo,
                'invoiceUuid' => $receipt->uuid_asignado, 'observation' => $receipt->observacion];
        }

        return ['register' => $register, 'sales' => $lines, 'receipts' => $receiptLines,
            'sessions' => $turns->map(fn ($turn): array => ['legacyId' => (int) $turn->legacy_id,
                'openedAt' => CarbonImmutable::parse($turn->opened_at)->setTimezone(config('cash.timezone'))->toIso8601String(),
                'closedAt' => $turn->closed_at === null ? null : CarbonImmutable::parse($turn->closed_at)->setTimezone(config('cash.timezone'))->toIso8601String()])->all()];
    }

    /** @param array<string, mixed> $snapshot
     * @param array<string, mixed> $legacy
     * @return array<string, mixed> */
    public function merge(array $snapshot, array $legacy): array
    {
        foreach (['sales', 'receipts'] as $key) {
            $snapshot[$key] = [...array_map(fn ($row): array => ['source' => 'v2', ...$row], $snapshot[$key] ?? []), ...$legacy[$key]];
        }
        $snapshot['version'] = 3;
        $snapshot['legacy'] = ['sessions' => $legacy['sessions']];
        $snapshot['origins'] = [];
        $snapshot['methods'] = [];
        $snapshot['documents'] = ['01' => '0.00', '03' => '0.00'];
        $snapshot['receiptCategories'] ??= [];
        $total = $credit = $receiptTotal = 0;
        foreach (['sales', 'receipts'] as $key) {
            foreach ($snapshot[$key] as $row) {
                $source = $row['source'];
                $amount = ReceiptMoney::cents($row['total']);
                $snapshot['origins'][$source] ??= ['sales' => 0, 'receipts' => 0, 'collections' => 0];
                $snapshot['origins'][$source][$key] += $amount;
                foreach ($row['payments'] as $method => $value) {
                    $cents = ReceiptMoney::cents($value);
                    $snapshot['methods'][$method] = ReceiptMoney::decimal(ReceiptMoney::cents($snapshot['methods'][$method] ?? '0') + $cents);
                    $snapshot['origins'][$source]['collections'] += $cents;
                }
                if ($key === 'sales') {
                    $total += $amount;
                    $credit += ReceiptMoney::cents($row['receivable']);
                    $type = $row['type'];
                    $snapshot['documents'][$type] = ReceiptMoney::decimal(ReceiptMoney::cents($snapshot['documents'][$type] ?? '0') + $amount);
                } else {
                    $receiptTotal += $amount;
                }
            }
        }
        // Keep native category amounts; classify V1 separately rather than guessing its catalog semantics.
        foreach ($snapshot['receipts'] as $row) {
            if ($row['source'] === 'v1') {
                $snapshot['receiptCategories']['legacy'] = ReceiptMoney::decimal(ReceiptMoney::cents($snapshot['receiptCategories']['legacy'] ?? '0') + ReceiptMoney::cents($row['total']));
            }
        }
        $snapshot['total'] = ReceiptMoney::decimal($total);
        $snapshot['receivable'] = ReceiptMoney::decimal($credit);
        $snapshot['receiptTotal'] = ReceiptMoney::decimal($receiptTotal);
        $snapshot['collections'] = ReceiptMoney::decimal(array_sum(array_map(ReceiptMoney::cents(...), $snapshot['methods'])));
        $snapshot['origins'] = array_map(fn ($amounts): array => array_map(ReceiptMoney::decimal(...), $amounts), $snapshot['origins']);
        ksort($snapshot['methods']);

        return $snapshot;
    }
}
