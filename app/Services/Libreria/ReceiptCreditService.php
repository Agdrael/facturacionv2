<?php

namespace App\Services\Libreria;

use App\Models\Libreria\Cliente;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

class ReceiptCreditService
{
    public function __construct(private ReceiptSourceService $sources, private ReceiptIntegrationService $integrations) {}

    public function register(stdClass $journal): void
    {
        $this->sources->require($journal->transmision_estado === 'ACEPTADO' && ! empty($journal->sello_recepcion), 'Solo se registran créditos de DTE aceptados en producción.');
        $this->sources->invoice('native', $journal->codigo_generacion);
        DB::transaction(function () use ($journal): void {
            $this->sources->advisory('receipt-accounting');
            $origin = DB::table('libreria.receipt_credit_origins')->where('journal_id', $journal->id)->lockForUpdate()->first();
            if ($origin?->applied_at !== null) {
                return;
            }
            $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
            $this->sources->require($document['identificacion']['ambiente'] === '01' && (int) $document['resumen']['condicionOperacion'] === 2, 'El documento debe ser una factura a crédito productiva.');
            if ($origin === null) {
                $data = $this->prepare($journal, $document);
                DB::table('libreria.receipt_credit_origins')->insert(['journal_id' => $journal->id, 'operation_key' => (string) Str::uuid(),
                    'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $data = json_decode($origin->data, true, 512, JSON_THROW_ON_ERROR);
            }
        }, 3);
        DB::transaction(function () use ($journal): void {
            $this->sources->advisory('receipt-accounting');
            $origin = DB::table('libreria.receipt_credit_origins')->where('journal_id', $journal->id)->lockForUpdate()->first();
            if ($origin->applied_at !== null) {
                return;
            }
            $data = json_decode($origin->data, true, 512, JSON_THROW_ON_ERROR);
            $legacy = $this->integrations->external('legacy', $data['legacy_key'], $data,
                fn (Connection $db): array => $this->legacy($db, $data, false));
            $platform = $this->integrations->external('platform', $data['platform_key'], $data,
                fn (Connection $db): array => $this->academic($db, $data, false));
            $this->rebaseAcademic($platform['groups']);
            foreach ($data['lines'] as $line) {
                $claim = $line['claim'];
                $academic = $claim !== null;
                $key = $academic ? 'academic:'.$platform['groups'][$line['group']]['id'].':'.$claim['financiamiento'] : 'native:'.$line['id'];
                $this->sources->require(! DB::table('libreria.receipt_obligations')->where('source_key', $key)->exists(), 'La cuota ya tiene una obligación vinculada; requiere conciliación.');
                $source = ['kind' => $academic ? 'academic' : 'sale', 'native_origin' => $journal->id,
                    'amount' => ReceiptMoney::decimal($line['amount']), 'remaining' => ReceiptMoney::decimal($line['amount'] - $line['initial']),
                    'invoice_remaining' => ReceiptMoney::decimal($data['remaining']), 'external_remaining' => ReceiptMoney::decimal($line['amount'] - $line['initial']),
                    'invoice' => $data['invoice'], 'description' => $line['name'].' · '.$data['invoice']['number'], 'legacy_code' => 'PAGO CXC '.$data['invoice']['number'], 'account' => $line['account'],
                    'legacy_cargo_id' => $legacy['cargo_id'], 'legacy_cargo_opening' => ReceiptMoney::decimal($data['remaining']),
                    'cargo_applied_baseline' => 0, 'legacy_client_id' => $data['legacy_client_id']];
                if ($academic) {
                    $group = $platform['groups'][$line['group']];
                    $source += ['cxc_id' => $group['id'], 'cxc_opening' => $group['remaining'], 'applied_baseline' => $this->sources->appliedForSource($group['id']),
                        'bank_paid' => 0, 'student_id' => (string) $claim['carnet_estudiante'], 'year' => (int) $claim['anio'], 'month' => (int) $claim['mes'],
                        'funding' => $claim['financiamiento'], 'balance_id' => $claim['saldo_id'], 'payer_name' => $data['payer_name'], 'payer_email' => $data['payer_email'],
                    ];
                    $source['legacy_code'] = self::monthName((int) $claim['mes']);
                }
                $this->sources->insertObligation($key, $academic ? 'alumno' : 'cliente', $academic ? (string) $claim['carnet_estudiante'] : (string) $data['client_id'], $source);
            }
            DB::table('libreria.receipt_credit_origins')->where('journal_id', $journal->id)->update(['applied_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function prepare(stdClass $journal, array $document): array
    {
        $sale = DB::table('libreria.ventas')->where('id', $journal->venta_id)->first();
        $this->sources->require($sale !== null && (string) $sale->operacion === '2' && $sale->estado !== 'ANULADO', 'La venta no es un crédito activo.');
        $client = Cliente::query()->whereKey($sale->cliente_id)->firstOrFail();
        $legacyClient = $this->sources->legacyClient(['type' => 'cliente', 'id' => (string) $client->id]);
        $details = DB::table('libreria.venta_detalles')->where('venta_id', $sale->id)->orderBy('id')->get();
        $claims = DB::table('libreria.venta_cuotas')->where('venta_id', $sale->id)->orderBy('id')->get()->all();
        $weights = [];
        foreach ($details as $detail) {
            $weights[$detail->id] = ReceiptMoney::cents((string) BigDecimal::of((string) $detail->valor_final)->toScale(2, RoundingMode::HalfUp));
        }
        $total = ReceiptMoney::cents($sale->total);
        $this->sources->require($weights !== [] && abs(array_sum($weights) - $total) <= count($weights), 'El detalle del crédito no concilia con el total facturado.');
        $first = array_key_first($weights);
        $weights[$first] += $total - array_sum($weights);
        $payments = json_decode($journal->pagos, true, 512, JSON_THROW_ON_ERROR);
        $initial = ReceiptMoney::cents($payments['paid']) - ReceiptMoney::cents($payments['change'] ?? '0');
        $this->sources->require($initial >= 0 && $initial <= $total, 'El pago inicial no concilia con la factura.');
        $allocation = ReceiptMoney::distribute($initial, $weights);
        $lines = [];
        foreach ($details as $detail) {
            $claim = null;
            if ($detail->carnet_estudiante !== null) {
                foreach ($claims as $index => $candidate) {
                    if ((string) $candidate->carnet_estudiante === (string) $detail->carnet_estudiante) {
                        $claim = (array) $candidate;
                        unset($claims[$index]);
                        break;
                    }
                }
                $this->sources->require($claim !== null, 'Falta la referencia académica del crédito.');
            }
            $group = $claim === null ? null : $claim['carnet_estudiante'].':'.$claim['anio'].':'.$claim['mes'];
            $lines[] = ['id' => $detail->id, 'name' => $detail->nombre, 'amount' => $weights[$detail->id], 'initial' => $allocation[$detail->id],
                'account' => $detail->cuenta_cargo, 'claim' => $claim, 'group' => $group];
        }
        $this->sources->require($claims === [], 'Existen cuotas sin detalle de factura.');
        $payer = json_decode($journal->cliente, true, 512, JSON_THROW_ON_ERROR);

        return ['invoice' => ['origin' => 'native', 'uuid' => $journal->codigo_generacion, 'number' => $journal->numero_control, 'amount' => $sale->total, 'journal_id' => $journal->id],
            'client_id' => $client->id, 'legacy_client_id' => $legacyClient, 'payer_name' => $payer['name'] ?? $client->nombre, 'payer_email' => $payer['email'] ?? $client->email,
            'remaining' => $total - $initial, 'lines' => $lines, 'date' => $document['identificacion']['fecEmi'],
            'legacy_key' => (string) Str::uuid(), 'platform_key' => (string) Str::uuid(), 'reverse_legacy_key' => (string) Str::uuid(), 'reverse_platform_key' => (string) Str::uuid()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{cargo_id: ?int}
     */
    private function legacy(Connection $db, array $data, bool $reverse): array
    {
        $this->sources->require(is_int($data['remaining']) && $data['remaining'] >= 0, 'El crédito no conserva un saldo válido en centavos.');
        $cargo = $db->table('public.saldo_cargos')->where('uuid_venta', $data['invoice']['uuid'])->lockForUpdate()->first();
        if ($reverse) {
            if ($cargo !== null && ! $cargo->anulado) {
                $this->sources->require(ReceiptMoney::cents($cargo->saldo_pendiente) === $data['remaining'], 'El crédito conserva cobros externos pendientes de conciliar.');
                $this->integrations->ledger($db, $data['legacy_client_id'], -$data['remaining']);
                $db->table('public.saldo_cargos')->where('id', $cargo->id)->update(['anulado' => true]);
            }

            return ['cargo_id' => $cargo?->id];
        }
        $this->sources->require($cargo === null, 'Ya existe un cargo externo para esta factura; requiere conciliación.');
        $this->integrations->ledger($db, $data['legacy_client_id'], $data['remaining']);
        $id = $db->table('public.saldo_cargos')->insertGetId(['id_cliente' => $data['legacy_client_id'], 'saldo' => $data['invoice']['amount'],
            'saldo_pendiente' => ReceiptMoney::decimal($data['remaining']), 'uuid_venta' => $data['invoice']['uuid'], 'anulado' => false]);

        return ['cargo_id' => $id];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{groups: array<string, array{id: int, remaining: string}>}
     */
    private function academic(Connection $db, array $data, bool $reverse): array
    {
        $groups = [];
        foreach ($data['lines'] as $line) {
            if ($line['claim'] !== null) {
                $groups[$line['group']][] = $line;
            }
        }
        ksort($groups);
        $result = [];
        foreach ($groups as $key => $lines) {
            $claim = $lines[0]['claim'];
            $face = array_sum(array_column($lines, 'amount'));
            $initial = array_sum(array_column($lines, 'initial'));
            $cxcRows = $db->table('public.cxc')->where('student_id', $claim['carnet_estudiante'])->whereIn('item_pago', [(string) $claim['mes'], str_pad((string) $claim['mes'], 2, '0', STR_PAD_LEFT)])->where('syear', $claim['anio'])->lockForUpdate()->get();
            $this->sources->require($cxcRows->count() <= 1, 'Existen copias CXC duplicadas para el mismo concepto y año.');
            $cxc = $cxcRows->first();
            $oldFace = $cxc === null ? 0 : ReceiptMoney::cents($cxc->monto);
            $oldRemaining = $cxc === null ? 0 : ReceiptMoney::cents($cxc->parciales ?? $cxc->monto);
            if (! $reverse && $cxc !== null && $oldFace > 0) {
                $this->validateAcademicBilling($db, $cxc, array_column(array_column($lines, 'claim'), 'financiamiento'), 'credit_application');
            }
            if ($reverse && $cxc === null) {
                continue;
            }
            $nextFace = $oldFace + ($reverse ? -$face : $face);
            $nextRemaining = $oldRemaining + ($reverse ? -($face - $initial) : ($face - $initial));
            $this->sources->require($nextFace >= 0 && $nextRemaining >= 0 && $nextRemaining <= $nextFace, 'La copia CXC no permite registrar o revertir el crédito.');
            $values = ['monto' => ReceiptMoney::decimal($nextFace), 'parciales' => ReceiptMoney::decimal($nextRemaining), 'facturado' => $nextFace > 0, 'recibo' => $nextRemaining === 0];
            if ($cxc === null) {
                $cxcId = $db->table('public.cxc')->insertGetId($values + ['student_id' => $claim['carnet_estudiante'], 'item_pago' => (string) $claim['mes'], 'syear' => $claim['anio'],
                    'fecha_transaccion' => $data['date'], 'custom_275' => $data['payer_name'], 'custom_277' => $data['payer_email'], 'cuenta_contable' => $lines[0]['account']]);
            } else {
                $cxcId = $cxc->id;
                $db->table('public.cxc')->where('id', $cxcId)->update($values);
            }
            foreach ($lines as $line) {
                if ($line['initial'] === 0) {
                    continue;
                }
                $this->initialPayment($db, $data, $line, $reverse);
            }
            $result[$key] = ['id' => $cxcId, 'remaining' => ReceiptMoney::decimal($nextRemaining)];
        }

        return ['groups' => $result];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $line
     */
    private function initialPayment(Connection $db, array $data, array $line, bool $reverse): void
    {
        $claim = $line['claim'];
        $balance = $db->table('public.saldo_pagos')->where('id_saldo', $claim['saldo_id'])->lockForUpdate()->first();
        $this->sources->require($balance !== null && (string) $balance->student_id === (string) $claim['carnet_estudiante']
            && (int) $balance->syear === (int) $claim['anio'] && (int) $balance->mes === (int) $claim['mes'], 'La referencia académica del crédito cambió.');
        [$paidColumn, $balanceColumn] = ReceiptSourceService::fundingColumns($claim['financiamiento']);
        $paid = ReceiptMoney::cents($balance->{$paidColumn} ?? 0);
        $remaining = ReceiptMoney::cents($balance->{$balanceColumn} ?? 0);
        $this->sources->require($reverse ? $paid >= $line['initial'] : $remaining >= $line['initial'], 'El saldo académico no permite aplicar el pago inicial.');
        $db->table('public.saldo_pagos')->where('id_saldo', $balance->id_saldo)->update([$paidColumn => ReceiptMoney::decimal($paid + ($reverse ? -$line['initial'] : $line['initial'])),
            $balanceColumn => ReceiptMoney::decimal($remaining + ($reverse ? $line['initial'] : -$line['initial'])), 'fecha_actualizado' => now(), 'habilitado' => 1]);
        if ($reverse) {
            $query = $db->table('public.detalle_pagos')->where('uid_dte', $data['invoice']['uuid'])->where('student_id', $claim['carnet_estudiante'])
                ->where('syear', $claim['anio'])->where('mes_pagado', $claim['mes'])->where('lugar', 'colecturia/credito')
                ->where('pago_beca', $claim['financiamiento'] === 'beca')->where('pago_bienhechor', $claim['financiamiento'] === 'bienhechor');
            $this->sources->require($query->count() === 1, 'El pago inicial no tiene una referencia única.');
            $query->update(['estado' => 'Anulado', 'updated_at' => now()]);
        } else {
            $db->table('public.detalle_pagos')->insert(['student_id' => $claim['carnet_estudiante'], 'mes_pagado' => $claim['mes'], 'syear' => $claim['anio'],
                'monto' => ReceiptMoney::decimal($line['initial']), 'fecha' => $data['date'], 'lugar' => 'colecturia/credito', 'uid_dte' => $data['invoice']['uuid'],
                'numero_dte' => $data['invoice']['number'], 'fecha_emision_dte' => $data['date'], 'facturado' => true,
                'pago_beca' => $claim['financiamiento'] === 'beca', 'pago_bienhechor' => $claim['financiamiento'] === 'bienhechor', 'fecha_creado' => now(), 'updated_at' => now()]);
        }
    }

    public function reverse(stdClass $journal): void
    {
        DB::transaction(function () use ($journal): void {
            $this->sources->advisory('receipt-accounting');
            $this->assertNoReceipts($journal->codigo_generacion);
            $origin = DB::table('libreria.receipt_credit_origins')->where('journal_id', $journal->id)->lockForUpdate()->first();
            if ($origin === null || $origin->reversed_at !== null) {
                return;
            }
            $data = json_decode($origin->data, true, 512, JSON_THROW_ON_ERROR);
            foreach (['platform', 'legacy'] as $target) {
                $db = $target === 'platform' ? $this->sources->platform() : $this->sources->legacy();
                if ($db->table('public.libreria_recibo_operaciones')->where('id', $data[$target.'_key'])->exists()) {
                    $result = $this->integrations->external($target, $data['reverse_'.$target.'_key'], $data,
                        fn (Connection $connection): array => $target === 'platform' ? $this->academic($connection, $data, true) : $this->legacy($connection, $data, true));
                    if ($target === 'platform') {
                        $this->rebaseAcademic($result['groups']);
                    }
                }
            }
            $budget = DB::table('libreria.receipt_invoice_balances')->where('invoice_uuid', $journal->codigo_generacion)->first();
            if ($budget !== null) {
                DB::table('libreria.receipt_obligations')->where('invoice_balance_id', $budget->id)->update(['remaining' => '0.00', 'updated_at' => now()]);
                DB::table('libreria.receipt_invoice_balances')->where('id', $budget->id)->update(['remaining' => '0.00', 'updated_at' => now()]);
            }
            DB::table('libreria.receipt_credit_origins')->where('journal_id', $journal->id)->update(['reversed_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    public function assertAcademicNotBilled(string $student, int $year, int $month, string $funding): void
    {
        if (! config('receipts.enabled')) {
            return;
        }
        $rows = $this->sources->platform()->table('public.cxc')->where('student_id', $student)->where('syear', $year)
            ->whereIn('item_pago', [(string) $month, str_pad((string) $month, 2, '0', STR_PAD_LEFT)])->where('facturado', true)->where('monto', '>', 0)->get();
        $this->sources->require($rows->count() <= 1, 'Hay CXC duplicadas que requieren conciliación antes de facturar.');
        if ($rows->isEmpty()) {
            return;
        }
        $cxc = $rows->first();
        $this->validateAcademicBilling($this->sources->platform(), $cxc, [$funding], 'sale_validation');
    }

    /** @param list<string> $requestedFunding */
    private function validateAcademicBilling(Connection $platform, stdClass $cxc, array $requestedFunding, string $stage): void
    {
        $billing = null;
        try {
            $billing = $this->academicBilling($platform, $cxc);
            foreach ($requestedFunding as $funding) {
                $this->assertFundingNotBilled($billing['funding'], $funding);
            }
            $this->sources->require($billing['amount'] === ReceiptMoney::cents($cxc->monto),
                'La CXC registra $'.ReceiptMoney::decimal(ReceiptMoney::cents($cxc->monto)).', pero se identificaron $'.ReceiptMoney::decimal($billing['amount']).' en facturas por financiamiento. Revisa las facturas de origen y su clasificación antes de emitir otra.');
        } catch (ValidationException $exception) {
            $reference = (string) Str::uuid();
            $reason = collect($exception->errors())->flatten()->implode(' ');
            $context = ['reference' => $reference, 'stage' => $stage, 'user_id' => auth()->id(), 'cxc_id' => $cxc->id,
                'student_id' => (string) $cxc->student_id, 'year' => (int) $cxc->syear, 'month' => (int) $cxc->item_pago,
                'requested_funding' => $requestedFunding, 'cxc_amount' => (string) $cxc->monto, 'cxc_remaining' => $cxc->parciales,
                'verified_amount' => $billing === null ? null : ReceiptMoney::decimal($billing['amount']), 'verified_funding' => $billing['funding'] ?? null,
                'reason' => $reason];
            try {
                $context['native_obligations'] = $this->nativeObligations($cxc->id)->orderBy('id')->limit(50)->get(['id', 'amount', 'data'])->map(function ($row): array {
                    $data = $this->sources->data($row);

                    return ['id' => $row->id, 'amount' => $row->amount, 'funding' => $data['funding'] ?? null, 'journal_id' => $data['native_origin'] ?? null,
                        'invoice_uuid' => $data['invoice']['uuid'] ?? null, 'invoice_number' => $data['invoice']['number'] ?? null];
                })->all();
                $details = $platform->table('public.detalle_pagos')->where('student_id', $cxc->student_id)->where('syear', $cxc->syear)->where('mes_pagado', (int) $cxc->item_pago)
                    ->orderBy('id_detalle')->limit(50)->get(['id_detalle', 'uid_dte', 'numero_dte', 'monto', 'pago_beca', 'pago_bienhechor', 'facturado', 'lugar', 'estado']);
                $context['academic_details'] = $details->map(fn ($row) => (array) $row)->all();
                $references = $details->map(fn ($row) => mb_strtolower(trim((string) $row->uid_dte)))->filter(fn ($uuid) => Str::isUuid($uuid))->unique()->values()->all();
                $context['legacy_invoices'] = $this->sources->legacy()->table('public.ventas')->whereIn(DB::raw('LOWER(TRIM(CAST(numero_factura AS TEXT)))'), $references)
                    ->orderBy('id_venta')->limit(50)->get(['id_venta', 'numero_factura', 'numero_control', 'total', 'operacion', 'anulado', 'n_carnet'])->map(fn ($row) => (array) $row)->all();
                $context['diagnostic_row_limit'] = 50;
            } catch (Throwable $diagnosticError) {
                $context['diagnostic_error_type'] = $diagnosticError::class;
            }
            Log::warning('CXC_FACTURACION_BLOQUEADA', $context);
            $labels = array_map($this->fundingLabel(...), $requestedFunding);
            $message = $reason.' Alumno: '.$cxc->student_id.'. Período: '.self::monthName((int) $cxc->item_pago).' '.$cxc->syear
                .'. Financiamiento solicitado: '.implode(', ', $labels).'. CXC #'.$cxc->id.'. Referencia de diagnóstico: '.$reference.'.';

            throw ValidationException::withMessages(['receipts' => $message]);
        }
    }

    /** @param list<string> $billed */
    private function assertFundingNotBilled(array $billed, string $funding): void
    {
        $label = 'el financiamiento «'.$this->fundingLabel($funding).'»';
        $this->sources->require(! in_array($funding, $billed, true), 'La parte de esta cuota correspondiente a '.$label.' ya está facturada. Cobra su saldo mediante un recibo, sin emitir otro DTE.');
    }

    private function fundingLabel(string $funding): string
    {
        return match ($funding) {
            'beca' => 'Beca', 'bienhechor' => 'Bienhechor', default => 'Responsable familiar',
        };
    }

    /** @return array{amount: int, funding: list<string>} */
    private function academicBilling(Connection $platform, stdClass $cxc): array
    {
        $amount = 0;
        $funding = [];
        foreach ($this->nativeObligations($cxc->id)->get(['amount', 'data']) as $obligation) {
            $data = $this->sources->data($obligation);
            $amount += ReceiptMoney::cents($obligation->amount);
            $funding[] = $data['funding'];
        }
        if ($amount === ReceiptMoney::cents($cxc->monto)) {
            return ['amount' => $amount, 'funding' => array_values(array_unique($funding))];
        }
        $details = $platform->table('public.detalle_pagos')->where('student_id', $cxc->student_id)->where('syear', $cxc->syear)
            ->where('mes_pagado', (int) $cxc->item_pago)->where('facturado', true)->whereNotNull('uid_dte')
            ->whereRaw('LOWER(TRIM(lugar)) = ?', ['colecturia'])
            ->where(function (Builder $query): void {
                $query->whereNull('estado')->orWhereRaw('LOWER(TRIM(estado)) <> ?', ['anulado']);
            })->get(['uid_dte', 'numero_dte', 'monto', 'pago_beca', 'pago_bienhechor']);
        $references = $details->map(fn ($row) => mb_strtolower(trim((string) $row->uid_dte)))->filter(fn ($uuid) => Str::isUuid($uuid))->unique()->values()->all();
        if ($references === []) {
            return ['amount' => $amount, 'funding' => array_values(array_unique($funding))];
        }
        $native = DB::table('libreria.venta_dtes')->whereIn(DB::raw('LOWER(TRIM(CAST(codigo_generacion AS TEXT)))'), $references)
            ->pluck('codigo_generacion')->map(fn ($uuid) => mb_strtolower(trim($uuid)))->all();
        $invoices = $this->sources->legacy()->table('public.ventas')->whereIn(DB::raw('LOWER(TRIM(CAST(numero_factura AS TEXT)))'), array_values(array_diff($references, $native)))
            ->where('operacion', '2')->where(function (Builder $query): void {
                $query->whereNull('anulado')->orWhere('anulado', false);
            })->get(['numero_factura', 'numero_control', 'n_carnet'])->groupBy(fn ($row) => mb_strtolower(trim($row->numero_factura)));
        $seen = [];
        foreach ($details as $detail) {
            $uuid = mb_strtolower(trim((string) $detail->uid_dte));
            $matches = $invoices->get($uuid);
            if ($matches === null) {
                continue;
            }
            $this->sources->require($matches->count() === 1, 'Hay facturas anteriores duplicadas para esta cuota. Requiere conciliación.');
            $invoice = $matches->first();
            $this->sources->require((string) $invoice->n_carnet === (string) $cxc->student_id
                && trim((string) $invoice->numero_control) !== '' && trim((string) $detail->numero_dte) === trim((string) $invoice->numero_control)
                && ! ($detail->pago_beca && $detail->pago_bienhechor), 'No se puede verificar la factura y el financiamiento de la CXC anterior. Requiere conciliación.');
            $type = $detail->pago_beca ? 'beca' : ($detail->pago_bienhechor ? 'bienhechor' : 'normal');
            $key = $uuid.':'.$type;
            $this->sources->require(! isset($seen[$key]) && ReceiptMoney::cents($detail->monto) > 0, 'Hay detalles académicos duplicados o importes inválidos en la CXC anterior. Requiere conciliación.');
            $seen[$key] = true;
            $amount += ReceiptMoney::cents($detail->monto);
            $funding[] = $type;
        }

        return ['amount' => $amount, 'funding' => array_values(array_unique($funding))];
    }

    private function nativeObligations(int $cxcId): Builder
    {
        $reversed = DB::table('libreria.receipt_credit_origins')->whereNotNull('reversed_at')->pluck('journal_id')->all();

        return DB::table('libreria.receipt_obligations')->where('data->cxc_id', $cxcId)->whereNotNull('data->native_origin')->whereNotIn('data->native_origin', $reversed);
    }

    /** @param array<string, array{id: int, remaining: string}> $groups */
    private function rebaseAcademic(array $groups): void
    {
        foreach ($groups as $group) {
            $baseline = $this->sources->appliedForSource($group['id']);
            foreach (DB::table('libreria.receipt_obligations')->where('data->cxc_id', $group['id'])->lockForUpdate()->get() as $obligation) {
                $source = $this->sources->data($obligation);
                $source['cxc_opening'] = $group['remaining'];
                $source['applied_baseline'] = $baseline;
                DB::table('libreria.receipt_obligations')->where('id', $obligation->id)->update(['data' => json_encode($source, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        }
    }

    public function assertNoReceipts(string $uuid): void
    {
        $this->sources->advisory('receipt-accounting');
        $this->sources->require(! DB::table('libreria.receipt_lines as l')->join('libreria.receipts as r', 'r.id', '=', 'l.receipt_id')
            ->where('l.data->invoice->uuid', $uuid)->where('r.status', '!=', 'ANULADO')->exists(), 'La factura tiene abonos activos o pendientes de reversión. Resuelve primero sus recibos.');
    }

    public static function monthName(int $month): string
    {
        return [1 => 'Matricula', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre'][$month] ?? 'Servicio '.$month;
    }
}
