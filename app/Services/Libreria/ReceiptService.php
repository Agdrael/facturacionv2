<?php

namespace App\Services\Libreria;

use App\Models\Dte\Cat017;
use App\Models\Libreria\Receipt;
use App\Models\User;
use App\Services\Empleado\EmployeeAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use stdClass;

class ReceiptService
{
    public function __construct(private ReceiptSourceService $sources, private ReceiptIntegrationService $integrations,
        private CashRegisterService $cash, private EmployeeAccessService $employees, private ReceiptSelectionService $selection,
        private ReceiptClientService $clients) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, User $actor): array
    {
        Gate::forUser($actor)->authorize('libreria.recibos.crear');
        abort_unless(config('receipts.enabled'), 503, 'El módulo de recibos todavía no está activado.');
        $receiptId = DB::transaction(function () use ($input, $actor): int {
            $this->sources->advisory('receipt-request:'.$input['requestKey']);
            $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
            $existing = DB::table('libreria.receipt_batches')->where('request_key', $input['requestKey'])->first();
            if ($existing !== null) {
                abort_unless((int) $existing->user_id === (int) $actor->id, 403);
                $this->sources->require(hash_equals($existing->request_hash, $hash), 'La solicitud ya fue registrada con otros datos. Conserva el recibo original.');

                return Receipt::query()->where('batch_id', $existing->id)->sole()->id;
            }
            $sessionId = $this->cash->saleSession($actor);
            $this->sources->advisory('receipt-accounting');
            $employee = $this->employees->employeeFor($actor);
            abort_if($employee === null, 403, 'No se encontró el empleado vinculado.');
            $total = ReceiptMoney::cents($input['amount']);
            $this->sources->require($total > 0, 'El importe del recibo debe ser mayor que cero.');
            $paid = $cash = 0;
            foreach ($input['payments'] as $payment) {
                $this->sources->require(Cat017::query()->whereKey($payment['method'])->exists(), 'El medio de pago no pertenece al catálogo autorizado.');
                $cents = ReceiptMoney::cents($payment['amount']);
                $this->sources->require($cents > 0, 'Cada medio de pago debe tener un importe positivo.');
                $paid += $cents;
                $cash += $payment['method'] === '01' ? $cents : 0;
            }
            $change = $paid - $total;
            $this->sources->require($change >= 0 && $change <= $cash, 'Completa el pago. El excedente solo puede devolverse del efectivo recibido.');
            if ($input['mode'] === 'catalog') {
                $concept = DB::table('public.catalogo_recibos')->where('id', $input['conceptId'])->first();
                $this->sources->require($concept !== null, 'El concepto no existe en el catálogo.');
                if (isset($input['studentId']) && ! $this->sources->requiresStudentForHistory($concept->nombre_recibo)) {
                    $recipient = $this->sources->recipient(['type' => 'alumno', 'id' => $input['studentId']]);
                } elseif (isset($input['recipient']['clientId'])) {
                    $recipient = $this->sources->recipient(['type' => 'cliente', 'id' => (string) $input['recipient']['clientId']]);
                } else {
                    $recipient = $this->sources->recipient(['type' => 'manual', 'id' => $input['requestKey'], 'name' => $input['recipient']['name']]);
                }
                $line = ['conceptId' => $input['conceptId'], 'studentId' => $input['studentId'] ?? null, 'amount' => $input['amount'], 'year' => $input['year'] ?? null,
                    'funding' => $input['funding'] ?? 'normal', 'account' => $input['account'] ?? null];
                $details = [$this->line($recipient, $line, $total, $concept)];
            } elseif ($input['mode'] === 'academic_cxc' && ! empty($input['academicItems'])) {
                $this->sources->require(! isset($input['sourceId']) && ! isset($input['invoice']), 'Usa únicamente la selección de CXC del recibo.');
                $details = [];
                $seen = [];
                $recipient = null;
                $sum = 0;
                foreach ($input['academicItems'] as $item) {
                    $key = $item['sourceId'].':'.$item['funding'];
                    $this->sources->require(! isset($seen[$key]), 'La misma CXC y responsable no pueden repetirse.');
                    $seen[$key] = true;
                    $resolved = $this->selection->resolve(['mode' => 'academic_cxc'] + $item, $actor->id);
                    $this->sources->require($recipient === null || $recipient['id'] === $resolved['recipient']['id'], 'Las CXC de un recibo deben pertenecer al mismo alumno.');
                    $recipient = $resolved['recipient'];
                    $amount = ReceiptMoney::cents($item['amount']);
                    $details[] = $this->line($recipient, $resolved['line'], $amount);
                    $sum += $amount;
                }
                $this->sources->require($sum === $total, 'El total debe coincidir con los abonos seleccionados.');
            } else {
                $resolved = $this->selection->resolve($input, $actor->id);
                $recipient = $resolved['recipient'];
                $line = $resolved['line'];
                $details = [$this->line($recipient, $line, $total)];
            }
            $this->account(config('receipts.cash_account'));
            foreach ($details as &$detail) {
                $this->account($detail['data']['account']);
                $detail['data']['cash_account'] = config('receipts.cash_account');
                $detail['data']['recipient_document'] = $input['recipient']['document'] ?? null;
                $detail['data']['recipient_document_type'] = $input['recipient']['documentType'] ?? null;
            }
            unset($detail);
            $requestId = DB::table('libreria.receipt_batches')->insertGetId(['request_key' => $input['requestKey'], 'request_hash' => $hash,
                'user_id' => $actor->id, 'environment' => config('services.factec.environment', '00'),
                'issuer' => json_encode((array) DB::table('public.opciones_factura_electronica_emisor')->first(['nombre', 'nombre_comercial']), JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now()]);
            $receipt = Receipt::query()->create(['uuid' => (string) Str::uuid(), 'batch_id' => $requestId, 'user_id' => $actor->id,
                'operator_name' => $actor->name, 'employee_code' => (string) $employee->getAttribute('codigo'),
                'register' => (string) $actor->getAttribute('puntoventa'), 'cash_session_id' => $sessionId,
                'recipient_type' => $recipient['type'], 'recipient_id' => $recipient['id'], 'recipient_name' => $recipient['name'],
                'payer_name' => $input['recipient']['name'] ?? $recipient['name'], 'observation' => $input['observation'] ?? null,
                'total' => ReceiptMoney::decimal($total), 'paid' => ReceiptMoney::decimal($paid), 'change' => ReceiptMoney::decimal($change)]);
            foreach ($input['payments'] as $payment) {
                DB::table('libreria.receipt_payments')->insert(['receipt_id' => $receipt->id, 'method' => $payment['method'],
                    'amount' => ReceiptMoney::decimal(ReceiptMoney::cents($payment['amount'])), 'reference' => $payment['reference'] ?? null]);
            }
            foreach ($details as $detail) {
                $lineId = DB::table('libreria.receipt_lines')->insertGetId(['receipt_id' => $receipt->id, 'obligation_id' => $detail['obligation_id'] ?? null,
                    'kind' => $detail['kind'], 'concept_id' => $detail['concept_id'] ?? null, 'description' => $detail['description'],
                    'amount' => $detail['amount'], 'data' => json_encode($detail['data'], JSON_THROW_ON_ERROR)]);
                DB::table('libreria.receipt_movements')->insert(['line_id' => $lineId, 'operation' => 'reserve', 'amount' => $detail['amount'], 'user_id' => $actor->id, 'created_at' => now()]);
                foreach (in_array($detail['kind'], ['academic', 'fotografia'], true) ? ['platform', 'legacy'] : ['legacy'] as $target) {
                    $this->queue($lineId, $target, 'apply');
                }
            }

            return $receipt->id;
        }, 3);

        return $this->present($this->integrations->process($receiptId));
    }

    /**
     * @param  array{type: string, id: string, name: string}  $recipient
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(array $recipient, array $line, int $amount, ?stdClass $catalogConcept = null): array
    {
        if (! empty($line['obligationKey'])) {
            $obligation = $this->sources->obligation($line['obligationKey'], $recipient);
            $data = $this->sources->data($obligation);
            $account = $line['account'] ?? $data['account'];
            $this->sources->require(DB::table('public.catalogo_contabilidad')->where('cuenta', $account)->where('cuenta', 'like', '1102%')->where('terminal', 'T')->exists(), 'Selecciona una cuenta terminal de cuentas por cobrar.');
            $data['original_account'] = $data['account'];
            $data['account'] = $account;
            $this->reserve($obligation, $amount);

            return ['kind' => $obligation->kind, 'obligation_id' => $obligation->id, 'description' => $data['description'], 'amount' => ReceiptMoney::decimal($amount), 'data' => $data];
        }
        $concept = $catalogConcept ?? DB::table('public.catalogo_recibos')->where('id', $line['conceptId'])->first();
        $this->sources->require($concept !== null, 'El concepto no existe en el catálogo.');
        $kind = $this->sources->conceptKind($concept->nombre_recibo, $concept->cuenta_abono);
        $this->sources->require($kind !== 'deuda' && trim((string) $concept->cuenta_abono) !== '00', 'Para abonar a CXC, selecciona una deuda y su factura; no un concepto de importe libre.');
        $this->sources->require(empty($line['account']) || $line['account'] === $concept->cuenta_abono, 'El tipo de recibo tiene una cuenta fija que no se puede sustituir.');
        $data = ['account' => $concept->cuenta_abono, 'legacy_code' => $concept->nombre_recibo];
        if ($this->sources->requiresStudentForHistory($concept->nombre_recibo)) {
            $this->sources->require(! empty($line['studentId']), 'Este tipo de recibo requiere seleccionar un alumno para su historial.');
            $student = $this->sources->recipient(['type' => 'alumno', 'id' => (string) $line['studentId']]);
            $data['history_student'] = ['id' => $student['id'], 'name' => $student['name']];
        }
        $obligationId = null;
        if ($kind === 'anticipo') {
            $data['legacy_code'] = 'PAGO EN ANTICIPO · '.$concept->nombre_recibo;
            $this->sources->require($recipient['type'] === 'cliente', 'El anticipo requiere seleccionar un cliente del catálogo actual.');
            $data['client_copy'] = $this->clients->prepare((int) $recipient['id']);
        } elseif ($kind === 'fotografia') {
            $data['legacy_code'] = 'FOTO · '.$concept->nombre_recibo;
            $this->sources->require($recipient['type'] === 'alumno' && isset($line['year']), 'Fotografías requiere seleccionar alumno y año académico.');
            $funding = $line['funding'] ?? 'normal';
            [$paidColumn, $balanceColumn] = ReceiptSourceService::fundingColumns($funding);
            $balance = $this->sources->platform()->table('public.saldo_pagos')->where('student_id', $recipient['id'])
                ->where('syear', $line['year'])->where('mes', config('receipts.photo_month'))->first();
            $this->sources->require($balance !== null, 'No existe el servicio de fotografías para ese alumno y año.');
            $key = 'photo:'.$recipient['id'].':'.$line['year'].':'.$funding;
            $data += ['kind' => 'fotografia', 'balance_id' => $balance->id_saldo, 'student_id' => $recipient['id'], 'year' => (int) $line['year'],
                'month' => (int) config('receipts.photo_month'), 'funding' => $funding];
            $obligation = DB::table('libreria.receipt_obligations')->where('source_key', $key)->lockForUpdate()->first();
            if ($obligation === null) {
                $initial = ReceiptMoney::decimal(ReceiptMoney::cents($balance->{$balanceColumn} ?? 0));
                $obligationId = DB::table('libreria.receipt_obligations')->insertGetId(['source_key' => $key, 'kind' => 'fotografia',
                    'recipient_type' => 'alumno', 'recipient_id' => $recipient['id'], 'amount' => $initial, 'opening_remaining' => $initial,
                    'remaining' => $initial, 'data' => json_encode($data), 'created_at' => now(), 'updated_at' => now()]);
                $obligation = DB::table('libreria.receipt_obligations')->where('id', $obligationId)->first();
            }
            $expected = ReceiptMoney::cents($obligation->opening_remaining) - $this->sources->appliedForObligation($obligation->id, 'platform');
            $this->sources->require(ReceiptMoney::cents($balance->{$balanceColumn} ?? 0) === $expected, 'El saldo de fotografías cambió fuera del módulo; requiere conciliación.');
            $this->reserve($obligation, $amount);
            $obligationId = $obligation->id;
        }

        return ['kind' => $kind, 'concept_id' => $concept->id, 'obligation_id' => $obligationId, 'description' => $concept->nombre_recibo,
            'amount' => ReceiptMoney::decimal($amount), 'data' => $data];
    }

    private function reserve(stdClass $obligation, int $amount): void
    {
        $remaining = ReceiptMoney::cents($obligation->remaining);
        $this->sources->require($remaining >= $amount, 'El abono supera el saldo disponible o reservado por otro recibo.');
        if ($obligation->invoice_balance_id !== null) {
            $budget = DB::table('libreria.receipt_invoice_balances')->where('id', $obligation->invoice_balance_id)->lockForUpdate()->first();
            $this->sources->require($budget !== null && ReceiptMoney::cents($budget->remaining) >= $amount, 'El saldo compartido de la factura no permite este abono.');
            DB::table('libreria.receipt_invoice_balances')->where('id', $budget->id)->update(['remaining' => ReceiptMoney::decimal(ReceiptMoney::cents($budget->remaining) - $amount), 'updated_at' => now()]);
        }
        DB::table('libreria.receipt_obligations')->where('id', $obligation->id)->update(['remaining' => ReceiptMoney::decimal($remaining - $amount), 'updated_at' => now()]);
    }

    public function annul(int $receiptId, User $actor, string $reason): Receipt
    {
        Gate::forUser($actor)->authorize('libreria.recibos.anular');
        $this->sources->require(mb_strlen(trim($reason)) >= 5, 'Explica el motivo de la anulación.');
        DB::transaction(function () use ($receiptId, $actor, $reason): void {
            $initial = Receipt::query()->findOrFail($receiptId);
            $this->cash->lockRegister($initial->register);
            $this->sources->advisory('receipt-accounting');
            $receipt = Receipt::query()->lockForUpdate()->findOrFail($receiptId);
            if ($receipt->annulled_at !== null) {
                return;
            }
            $employee = $this->employees->employeeFor($actor);
            abort_if($employee === null, 403, 'No se encontró el empleado vinculado.');
            $receipt->update(['status' => 'ANULACION_PENDIENTE', 'annulled_at' => now(), 'annulled_by' => $actor->id,
                'annulled_employee_code' => (string) $employee->getAttribute('codigo'), 'annulment_reason' => trim($reason)]);
            foreach (DB::table('libreria.receipt_lines')->where('receipt_id', $receiptId)->orderBy('id')->get() as $line) {
                foreach (DB::table('libreria.receipt_integrations')->where('line_id', $line->id)->where('operation', 'apply')->get() as $integration) {
                    $this->queue($line->id, $integration->target, 'reverse');
                }
            }
        }, 3);

        return $this->integrations->process($receiptId);
    }

    public function accessible(User $actor, int $id, string $action): Receipt
    {
        Gate::forUser($actor)->authorize('libreria.recibos.'.$action);
        $receipt = Receipt::query()->findOrFail($id);
        abort_unless($actor->can('libreria.recibos.ver_todos') || (int) $receipt->user_id === (int) $actor->id, 403);

        return $receipt;
    }

    /** @param array{id: string, name: string}|null $student
     * @return array<string, mixed> */
    public function present(Receipt $receipt, ?array $student = null): array
    {
        return ['id' => $receipt->id, 'number' => $receipt->number(), 'recipient' => $receipt->recipient_name, 'payer' => $receipt->payer_name, 'student' => $student,
            'operator' => $receipt->operator_name, 'register' => $receipt->register, 'total' => $receipt->total, 'status' => $receipt->status,
            'date' => $receipt->created_at->setTimezone('America/El_Salvador')->format('d/m/Y H:i'),
            'annulmentReason' => $receipt->annulment_reason, 'ticketUrl' => route('libreria.recibos.ticket', $receipt->id),
            'retryUrl' => route('libreria.recibos.retry', $receipt->id), 'annulUrl' => route('libreria.recibos.annul', $receipt->id),
            'errors' => DB::table('libreria.receipt_integrations as i')->join('libreria.receipt_lines as l', 'l.id', '=', 'i.line_id')
                ->where('l.receipt_id', $receipt->id)->whereNotNull('i.error')->pluck('i.error')->unique()->values()->all()];
    }

    private function queue(int $lineId, string $target, string $operation): void
    {
        DB::table('libreria.receipt_integrations')->insertOrIgnore(['line_id' => $lineId, 'target' => $target, 'operation' => $operation,
            'operation_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function account(?string $account): void
    {
        $this->sources->require(is_string($account) && trim($account) !== '' && DB::table('public.catalogo_contabilidad')->where('cuenta', $account)->exists(), 'El concepto necesita una cuenta contable válida.');
    }
}
