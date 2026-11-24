<?php

namespace App\Services\Libreria;

use App\Models\Libreria\Receipt;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class ReceiptIntegrationService
{
    public function __construct(private ReceiptSourceService $sources, private ReceiptClientService $clients) {}

    public function process(int $receiptId): Receipt
    {
        $ids = DB::table('libreria.receipt_integrations as i')->join('libreria.receipt_lines as l', 'l.id', '=', 'i.line_id')
            ->where('l.receipt_id', $receiptId)->where('i.status', '!=', 'APLICADO')->orderBy('i.id')->pluck('i.id');
        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id): void {
                    $this->sources->advisory('receipt-accounting');
                    $integration = DB::table('libreria.receipt_integrations')->where('id', $id)->lockForUpdate()->first();
                    if ($integration === null || $integration->status === 'APLICADO') {
                        return;
                    }
                    $line = DB::table('libreria.receipt_lines')->where('id', $integration->line_id)->first();
                    $receipt = Receipt::query()->lockForUpdate()->whereKey($line->receipt_id)->firstOrFail();
                    if ($integration->operation === 'apply' && $receipt->annulled_at !== null) {
                        $this->cancelUnapplied($integration);

                        return;
                    }
                    if ($integration->operation === 'reverse') {
                        $original = DB::table('libreria.receipt_integrations')->where('line_id', $line->id)->where('target', $integration->target)->where('operation', 'apply')->first();
                        if ($original->status !== 'APLICADO') {
                            $this->cancelUnapplied($original);
                            $original = DB::table('libreria.receipt_integrations')->where('id', $original->id)->first();
                        }
                        if ($original->status === 'CANCELADO') {
                            DB::table('libreria.receipt_integrations')->where('id', $id)->update(['status' => 'CANCELADO', 'error' => null, 'updated_at' => now()]);

                            return;
                        }
                    }
                    $data = json_decode($line->data, true, 512, JSON_THROW_ON_ERROR);
                    $result = $this->external($integration->target, $integration->operation_key, ['line' => (array) $line, 'operation' => $integration->operation],
                        fn (Connection $db): array => $integration->target === 'platform'
                            ? $this->platform($db, $line, $receipt, $data, $integration->operation)
                            : $this->legacy($db, $line, $receipt, $data, $integration->operation));
                    if ($integration->target === 'legacy' && $integration->operation === 'apply' && isset($data['client_copy'], $result['legacy_client_id'])) {
                        $this->clients->remember($data['client_copy']['client_id'], $result['legacy_client_id'], $receipt->user_id);
                    }
                    DB::table('libreria.receipt_integrations')->where('id', $id)->update(['status' => 'APLICADO', 'result' => json_encode($result), 'error' => null, 'updated_at' => now()]);
                }, 3);
            } catch (ValidationException $e) {
                $this->failure($id, 'REVISION', collect($e->errors())->flatten()->implode(' '));
            } catch (Throwable $e) {
                report($e);
                $this->failure($id, 'PENDIENTE', 'La conexión externa no confirmó la operación. Puedes reintentar sin repetir el cobro.');
            }
        }

        return DB::transaction(function () use ($receiptId): Receipt {
            $this->sources->advisory('receipt-accounting');
            $receipt = Receipt::query()->lockForUpdate()->findOrFail($receiptId);
            $states = DB::table('libreria.receipt_integrations as i')->join('libreria.receipt_lines as l', 'l.id', '=', 'i.line_id')
                ->where('l.receipt_id', $receiptId)->whereNotIn('i.status', ['APLICADO', 'CANCELADO'])->pluck('i.status');
            $receipt->status = $receipt->annulled_at !== null ? ($states->isEmpty() ? 'ANULADO' : 'ANULACION_PENDIENTE')
                : ($states->isEmpty() ? 'EMITIDO' : ($states->contains('REVISION') ? 'REVISION' : 'PENDIENTE_APLICACION'));
            if ($receipt->status === 'ANULADO') {
                foreach (DB::table('libreria.receipt_lines')->where('receipt_id', $receiptId)->orderBy('id')->get() as $line) {
                    $inserted = DB::table('libreria.receipt_movements')->insertOrIgnore(['line_id' => $line->id, 'operation' => 'reverse', 'amount' => $line->amount, 'user_id' => $receipt->annulled_by, 'created_at' => now()]);
                    if ($inserted && $line->obligation_id !== null) {
                        $obligation = DB::table('libreria.receipt_obligations')->where('id', $line->obligation_id)->lockForUpdate()->first();
                        $amount = ReceiptMoney::cents($line->amount);
                        DB::table('libreria.receipt_obligations')->where('id', $obligation->id)->update(['remaining' => ReceiptMoney::decimal(ReceiptMoney::cents($obligation->remaining) + $amount), 'updated_at' => now()]);
                        if ($obligation->invoice_balance_id !== null) {
                            $budget = DB::table('libreria.receipt_invoice_balances')->where('id', $obligation->invoice_balance_id)->lockForUpdate()->first();
                            DB::table('libreria.receipt_invoice_balances')->where('id', $budget->id)->update(['remaining' => ReceiptMoney::decimal(ReceiptMoney::cents($budget->remaining) + $amount), 'updated_at' => now()]);
                        }
                    }
                }
            }
            $receipt->save();

            return $receipt;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(Connection): array<string, mixed>  $effect
     * @return array<string, mixed>
     */
    public function external(string $target, string $key, array $payload, callable $effect): array
    {
        $db = $target === 'platform' ? $this->sources->platform() : $this->sources->legacy();

        return $db->transaction(function () use ($db, $key, $payload, $effect): array {
            if ($db->getDriverName() === 'pgsql') {
                $db->select("SELECT pg_advisory_xact_lock(hashtext('libreria.recibo.operacion'), hashtext(?))", [$key]);
            }
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $previous = $db->table('public.libreria_recibo_operaciones')->where('id', $key)->lockForUpdate()->first();
            if ($previous !== null) {
                $this->sources->require(hash_equals($previous->payload_hash, $hash), 'La operación externa ya tiene otro contenido; requiere conciliación.');

                return json_decode($previous->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $effect($db);
            $db->table('public.libreria_recibo_operaciones')->insert(['id' => $key, 'payload_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{detail_id: int}
     */
    private function platform(Connection $db, stdClass $line, Receipt $receipt, array $data, string $operation): array
    {
        $reverse = $operation === 'reverse';
        $amount = ReceiptMoney::cents($line->amount);
        $balance = $db->table('public.saldo_pagos')->where('id_saldo', $data['balance_id'])->lockForUpdate()->first();
        $this->sources->require($balance !== null && (string) $balance->student_id === (string) $data['student_id']
            && (int) $balance->mes === (int) $data['month'] && (int) $balance->syear === (int) $data['year'], 'La referencia académica ya no coincide con el alumno, concepto y año.');
        [$paidColumn, $balanceColumn] = ReceiptSourceService::fundingColumns($data['funding']);
        $paid = ReceiptMoney::cents($balance->{$paidColumn} ?? 0);
        $remaining = ReceiptMoney::cents($balance->{$balanceColumn} ?? 0);
        if (! $reverse) {
            $obligation = DB::table('libreria.receipt_obligations')->where('id', $line->obligation_id)->firstOrFail();
            if ($line->kind === 'academic') {
                $this->sources->verifyOutstanding($obligation, $this->sources->data($obligation));
            } else {
                $expected = ReceiptMoney::cents($obligation->opening_remaining) - $this->sources->appliedForObligation($obligation->id, 'platform');
                $this->sources->require($remaining === $expected, 'El saldo de fotografías cambió fuera del módulo; requiere conciliación.');
            }
        }
        $this->sources->require($reverse ? $paid >= $amount : $remaining >= $amount, 'El saldo académico cambió; no se puede aplicar el importe reservado.');
        if ($line->kind === 'academic') {
            $cxc = $db->table('public.cxc')->where('id', $data['cxc_id'])->lockForUpdate()->first();
            $this->sources->require($cxc !== null && $cxc->facturado && (string) $cxc->student_id === (string) $data['student_id']
                && (int) $cxc->item_pago === (int) $data['month'] && (int) $cxc->syear === (int) $data['year'], 'La CXC ya no corresponde a la obligación cobrada.');
            $this->sources->require($reverse || ! $cxc->recibo, 'La CXC ya tiene recibo de pago; requiere revisión antes de aplicar otro cobro.');
            $outstanding = ReceiptMoney::cents($cxc->parciales ?? $cxc->monto);
            $next = $outstanding + ($reverse ? $amount : -$amount);
            $this->sources->require($next >= 0 && $next <= ReceiptMoney::cents($cxc->monto), 'El abono supera la CXC o su reversión supera lo facturado.');
            $db->table('public.cxc')->where('id', $cxc->id)->update(['parciales' => ReceiptMoney::decimal($next), 'recibo' => $next === 0]);
        }
        if ($reverse) {
            $application = $this->application($line->id, 'platform');
            $detail = $db->table('public.detalle_pagos')->where('id_detalle', $application['detail_id'])->lockForUpdate()->first();
            $this->sources->require($detail !== null && $detail->estado !== 'Anulado' && ReceiptMoney::cents((string) BigDecimal::of((string) $detail->monto)->toScale(2, RoundingMode::HalfUp)) === $amount,
                'El movimiento académico original cambió; requiere conciliación.');
            $db->table('public.detalle_pagos')->where('id_detalle', $detail->id_detalle)->update(['estado' => 'Anulado', 'updated_at' => now()]);
            $detailId = $detail->id_detalle;
        } else {
            $detailId = $db->table('public.detalle_pagos')->insertGetId([
                'student_id' => $data['student_id'], 'mes_pagado' => $data['month'], 'fecha' => $receipt->created_at->setTimezone('America/El_Salvador')->toDateString(),
                'monto' => $line->amount, 'lugar' => 'Colecturia/Recibo', 'syear' => $data['year'],
                'uid_dte' => $data['invoice']['uuid'] ?? null, 'numero_dte' => $data['invoice']['number'] ?? null,
                'facturado' => isset($data['invoice']), 'pago_beca' => $data['funding'] === 'beca', 'pago_bienhechor' => $data['funding'] === 'bienhechor',
                'fecha_creado' => now(), 'updated_at' => now(),
            ], 'id_detalle');
        }
        $db->table('public.saldo_pagos')->where('id_saldo', $balance->id_saldo)->update([
            $paidColumn => ReceiptMoney::decimal($paid + ($reverse ? -$amount : $amount)),
            $balanceColumn => ReceiptMoney::decimal($remaining + ($reverse ? $amount : -$amount)), 'fecha_actualizado' => now(), 'habilitado' => 1,
        ]);

        return ['detail_id' => $detailId];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function legacy(Connection $db, stdClass $line, Receipt $receipt, array $data, string $operation): array
    {
        $reverse = $operation === 'reverse';
        $amount = ReceiptMoney::cents($line->amount);
        $clientId = $data['legacy_client_id'] ?? null;
        if ($line->kind === 'anticipo' && isset($data['client_copy'])) {
            $clientId = $reverse ? $this->application($line->id, 'legacy')['legacy_client_id'] : $this->clients->apply($db, $data['client_copy']);
        }
        $cargo = null;
        if (isset($data['invoice'])) {
            $cargo = $db->table('public.saldo_cargos')->where('uuid_venta', $data['invoice']['uuid'])
                ->where(function ($q): void {
                    $q->whereNull('anulado')->orWhere('anulado', false);
                })->lockForUpdate()->first();
            $clientId ??= $cargo?->id_cliente;
        }
        if ($line->kind === 'anticipo' || $cargo !== null) {
            if (! $reverse && isset($data['legacy_cargo_opening'])) {
                $this->sources->verifyCargo($cargo, $data, $data['legacy_cargo_opening']);
            }
            $this->sources->require($clientId !== null, 'Falta el cliente contable del movimiento.');
            $this->ledger($db, $clientId, $reverse ? $amount : -$amount);
            $db->table('public.saldo_abonos')->insert(['id_cliente' => $clientId, 'saldo' => ReceiptMoney::decimal($reverse ? -$amount : $amount)]);
        }
        if ($cargo !== null) {
            $next = ReceiptMoney::cents($cargo->saldo_pendiente) + ($reverse ? $amount : -$amount);
            $this->sources->require($next >= 0 && $next <= ReceiptMoney::cents($cargo->saldo), 'El cargo contable no permite aplicar este movimiento.');
            $db->table('public.saldo_cargos')->where('id', $cargo->id)->update(['saldo_pendiente' => ReceiptMoney::decimal($next)]);
        }
        if ($reverse) {
            $application = $this->application($line->id, 'legacy');
            foreach ($application['receipt_ids'] as $id) {
                $previous = $db->table('public.recibos')->where('id', $id)->lockForUpdate()->first();
                $this->sources->require($previous !== null && ! $previous->anulado, 'El recibo compatible fue modificado fuera del módulo.');
                $db->table('public.recibos')->where('id', $id)->update(['anulado' => true]);
                $db->table('public.recibos_anulados')->insert(['id_recibo' => $id, 'cuenta_cargo' => $data['cash_account'], 'id_usuario' => null]);
            }

            return $application;
        }
        $payments = DB::table('libreria.receipt_payments')->where('receipt_id', $receipt->id)->orderBy('id')->get();
        $weights = [];
        foreach ($payments as $payment) {
            $weights[$payment->id] = ReceiptMoney::cents($payment->amount);
        }
        $cashChange = ReceiptMoney::cents($receipt->change);
        foreach ($payments as $payment) {
            if ($payment->method === '01' && $cashChange > 0) {
                $deduct = min($weights[$payment->id], $cashChange);
                $weights[$payment->id] -= $deduct;
                $cashChange -= $deduct;
            }
        }
        $parts = [];
        foreach (DB::table('libreria.receipt_lines')->where('receipt_id', $receipt->id)->orderBy('id')->get(['id', 'amount']) as $receiptLine) {
            $parts = ReceiptMoney::distribute(ReceiptMoney::cents($receiptLine->amount), $weights);
            if ($receiptLine->id === $line->id) {
                break;
            }
            foreach ($parts as $paymentId => $part) {
                $weights[$paymentId] -= $part;
            }
        }
        $ids = [];
        foreach ($payments as $payment) {
            if ($parts[$payment->id] === 0) {
                continue;
            }
            $date = $receipt->created_at->setTimezone('America/El_Salvador')->toDateString();
            $legacyId = $db->table('public.recibos')->insertGetId([
                'uuid_asignado' => $data['invoice']['uuid'] ?? '----', 'nombre_cliente' => mb_substr($receipt->recipient_name, 0, 60),
                'fecha' => $date, 'fecha_sistema' => $date, 'cuenta_cargo' => $data['cash_account'],
                'codigo_recibo' => mb_substr($data['legacy_code'] ?? $line->description, 0, 250), 'cuenta_abono' => $data['account'],
                'numero_carnet' => $data['student_id'] ?? null, 'numero_caja' => $receipt->register,
                'observacion' => mb_substr($receipt->number().' · '.$line->description.' · Medio '.$payment->method.' '.$payment->reference.' · '.$receipt->observation, 0, 500),
                'precio' => ReceiptMoney::decimal($parts[$payment->id]), 'tarjeta' => in_array($payment->method, ['02', '03'], true),
                'cxc' => in_array($line->kind, ['academic', 'legacy', 'sale'], true), 'cxc_id' => $cargo?->id, 'id_detalle_pago' => null,
            ]);
            $db->table('public.libreria_recibo_documentos')->insert(['legacy_receipt_id' => $legacyId, 'receipt_uuid' => $receipt->uuid, 'line_id' => $line->id]);
            $ids[] = $legacyId;
        }

        return ['receipt_ids' => $ids, 'legacy_client_id' => $clientId];
    }

    public function ledger(Connection $db, int $clientId, int $delta): void
    {
        $db->table('public.clientes')->where('id', $clientId)->lockForUpdate()->firstOrFail();
        $row = $db->table('public.saldo_clientes')->where('id_cliente', $clientId)->lockForUpdate()->first();
        if ($row === null) {
            $opening = ReceiptMoney::cents($db->table('public.saldo_cargos')->where('id_cliente', $clientId)
                ->where(function ($q): void {
                    $q->whereNull('anulado')->orWhere('anulado', false);
                })->sum('saldo_pendiente'));
            $db->table('public.saldo_clientes')->insert(['id_cliente' => $clientId, 'saldo' => ReceiptMoney::decimal($opening + $delta), 'movimiento' => $delta < 0 ? 'abono' : 'cargo']);
        } else {
            $db->table('public.saldo_clientes')->where('id_cliente', $clientId)->update(['saldo' => ReceiptMoney::decimal(ReceiptMoney::cents($row->saldo) + $delta), 'movimiento' => $delta < 0 ? 'abono' : 'cargo']);
        }
    }

    /** @return array<string, mixed> */
    private function application(int $lineId, string $target): array
    {
        $row = DB::table('libreria.receipt_integrations')->where('line_id', $lineId)->where('target', $target)->where('operation', 'apply')->where('status', 'APLICADO')->first();
        $this->sources->require($row !== null, 'Primero debe recuperarse la confirmación del movimiento original.');

        return json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);
    }

    public function cancelUnapplied(stdClass $integration): void
    {
        $db = $integration->target === 'platform' ? $this->sources->platform() : $this->sources->legacy();
        $external = $db->table('public.libreria_recibo_operaciones')->where('id', $integration->operation_key)->first();
        DB::table('libreria.receipt_integrations')->where('id', $integration->id)->update([
            'status' => $external === null ? 'CANCELADO' : 'APLICADO', 'error' => null,
            'result' => $external?->result, 'updated_at' => now(),
        ]);
    }

    private function failure(int $id, string $status, string $error): void
    {
        DB::table('libreria.receipt_integrations')->where('id', $id)->where('status', '!=', 'APLICADO')
            ->update(['status' => $status, 'error' => $error, 'updated_at' => now()]);
    }
}
