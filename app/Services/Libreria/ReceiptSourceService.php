<?php

namespace App\Services\Libreria;

use App\Models\Libreria\Cliente;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

class ReceiptSourceService
{
    public function legacy(): Connection
    {
        return DB::connection(config('receipts.legacy_connection'));
    }

    public function platform(): Connection
    {
        return DB::connection(config('receipts.platform_connection'));
    }

    /** @return list<array{id: int, name: string, price: string, account: ?string, kind: string, requiresAccount: bool}> */
    public function concepts(): array
    {
        return array_values(DB::table('public.catalogo_recibos as c')
            ->orderBy('c.nombre_recibo')->get(['c.id', 'c.nombre_recibo', 'c.precio', 'c.cuenta_abono'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->nombre_recibo, 'price' => (string) $c->precio, 'account' => $c->cuenta_abono,
                'kind' => $this->conceptKind($c->nombre_recibo, $c->cuenta_abono), 'requiresAccount' => trim((string) $c->cuenta_abono) === '00'])->all());
    }

    public function conceptKind(string $name, ?string $account): string
    {
        $name = Str::upper(Str::ascii(trim($name)));
        if (trim((string) $account) === '00' || str_starts_with($name, 'PAGO CXC') || str_starts_with($name, 'ABONAR CXC')) {
            return 'deuda';
        }
        if (str_starts_with($name, 'PAGO EN ANTICIPO') || str_starts_with($name, 'PAGO ANTICIPO')) {
            return 'anticipo';
        }

        return str_starts_with($name, 'FOTO') ? 'fotografia' : 'normal';
    }

    /** @return list<array{account: string, name: string}> */
    public function receivableAccounts(): array
    {
        return array_values(DB::table('public.catalogo_contabilidad')->where('cuenta', 'like', '1102%')->where('terminal', 'T')
            ->orderBy('cuenta')->get(['cuenta', 'descripcion'])->map(fn ($row) => ['account' => $row->cuenta, 'name' => $row->descripcion])->all());
    }

    /** @return list<array{type: string, id: string, name: string, document?: ?string, documentType?: string}> */
    public function recipients(string $type, string $search): array
    {
        $term = '%'.mb_strtolower(trim($search)).'%';
        if ($type === 'alumno') {
            return array_values($this->platform()->table('public.students')->where(function ($q) use ($term): void {
                $q->whereRaw('LOWER(first_name) LIKE ?', [$term])->orWhereRaw('LOWER(last_name) LIKE ?', [$term]);
                if (ctype_digit(trim($term, '%'))) {
                    $q->orWhere('student_id', trim($term, '%'));
                }
            })->orderBy('student_id')->limit(30)->get(['student_id', 'first_name', 'middle_name', 'last_name'])
                ->map(fn ($s) => ['type' => 'alumno', 'id' => (string) $s->student_id, 'name' => trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name])))])->all());
        }
        $query = $type === 'legacy_cliente' ? $this->legacy()->table('public.clientes') : DB::table('libreria.clientes');

        return array_values($query->where(function ($query) use ($term): void {
            $query->whereRaw('LOWER(nombre) LIKE ?', [$term])->orWhere('dui', 'like', $term)->orWhere('nit', 'like', $term);
        })->orderBy('nombre')->limit(30)->get(['id', 'nombre', 'dui', 'nit'])
            ->map(fn ($c) => ['type' => $type, 'id' => (string) $c->id, 'name' => $c->nombre,
                'document' => trim((string) $c->nit) !== '' ? $c->nit : $c->dui,
                'documentType' => trim((string) $c->nit) !== '' ? 'NIT' : 'DUI'])->all());
    }

    /**
     * @param  array{type: string, id: string, name?: ?string}  $input
     * @return array{type: string, id: string, name: string}
     */
    public function recipient(array $input): array
    {
        $this->require(in_array($input['type'], ['manual', 'alumno', 'cliente', 'legacy_cliente'], true), 'Tipo de destinatario inválido.');
        if ($input['type'] === 'manual') {
            $this->require(trim($input['name'] ?? '') !== '', 'Indica el nombre del destinatario.');

            return ['type' => 'manual', 'id' => $input['id'], 'name' => trim($input['name'])];
        }
        $this->require(ctype_digit((string) $input['id']) && (int) $input['id'] > 0, 'Selecciona un destinatario válido.');
        if ($input['type'] === 'alumno') {
            $student = $this->platform()->table('public.students')->where('student_id', $input['id'])->first();
            $this->require($student !== null, 'El alumno no existe.');

            return ['type' => 'alumno', 'id' => (string) $student->student_id, 'name' => trim(implode(' ', array_filter([$student->first_name, $student->middle_name ?? '', $student->last_name])))];
        }
        $row = $input['type'] === 'legacy_cliente'
            ? $this->legacy()->table('public.clientes')->where('id', $input['id'])->first()
            : Cliente::query()->whereKey($input['id'])->first();
        $this->require($row !== null, 'El cliente no existe.');

        return ['type' => $input['type'], 'id' => (string) $row->id, 'name' => $row->nombre];
    }

    /** @param array{type: string, id: string, name?: string} $recipient */
    public function legacyClient(array $recipient): int
    {
        if ($recipient['type'] === 'legacy_cliente') {
            return (int) $recipient['id'];
        }
        $this->require($recipient['type'] === 'cliente', 'Selecciona un cliente registrado para el anticipo.');
        $linked = DB::table('libreria.receipt_client_links')->where('client_id', $recipient['id'])->value('legacy_client_id');
        if ($linked !== null) {
            $this->require($this->legacy()->table('public.clientes')->where('id', $linked)->exists(), 'El vínculo contable del cliente ya no existe.');

            return (int) $linked;
        }
        $client = Cliente::query()->whereKey($recipient['id'])->firstOrFail();
        $query = $this->legacy()->table('public.clientes')->where(function ($q) use ($client): void {
            $q->whereRaw('1 = 0');
            foreach (['dui', 'nit'] as $field) {
                if (trim((string) $client->getAttribute($field)) !== '') {
                    $q->orWhere($field, trim((string) $client->getAttribute($field)));
                }
            }
        });
        $ids = $query->limit(2)->pluck('id');
        $this->require($ids->count() === 1, 'El cliente necesita una correspondencia única por DUI o NIT con el sistema anterior.');

        return (int) $ids->first();
    }

    /** @return array{origin: 'native', uuid: string, number: string, amount: string, journal_id: int}|array{origin: 'legacy', uuid: string, number: string, amount: string, student_id: string, sale_id: int} */
    public function invoice(string $origin, string $reference): array
    {
        if ($origin === 'native') {
            $row = DB::table('libreria.venta_dtes')->where('codigo_generacion', $reference)->first();
            $this->require($row !== null && $row->transmision_estado === 'ACEPTADO' && ! empty($row->sello_recepcion), 'Selecciona un DTE aceptado en producción.');
            $sale = DB::table('libreria.ventas')->where('id', $row->venta_id)->first();
            $this->require($sale !== null && (string) $sale->operacion === '2' && $sale->estado !== 'ANULADO'
                && ! DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $row->id)->exists(), 'La factura debe estar a crédito, activa y sin invalidación.');

            return ['origin' => 'native', 'uuid' => $row->codigo_generacion, 'number' => $row->numero_control, 'amount' => (string) $sale->total, 'journal_id' => $row->id];
        }
        $row = $this->legacy()->table('public.ventas')->where('numero_factura', $reference)->first();
        $this->require($row !== null && ! $row->anulado && (string) $row->operacion === '2' && Str::isUuid($row->numero_factura), 'La factura anterior debe existir, estar a crédito y no estar anulada.');
        $this->require(! DB::table('libreria.venta_dtes as d')->join('libreria.venta_invalidaciones as i', 'i.venta_dte_id', '=', 'd.id')->where('d.codigo_generacion', $row->numero_factura)->exists(), 'La factura tiene una solicitud de invalidación.');

        return ['origin' => 'legacy', 'uuid' => $row->numero_factura, 'number' => $row->numero_control, 'amount' => (string) $row->total, 'student_id' => (string) ($row->n_carnet ?? ''), 'sale_id' => $row->id_venta];
    }

    /** @return array<string, mixed> */
    public function academicSource(int $id, string $funding): array
    {
        $this->require(in_array($funding, ['normal', 'beca', 'bienhechor'], true), 'Responsable académico desconocido.');
        $row = $this->platform()->table('public.cxc')->where('id', $id)->first();
        $this->require($row !== null && $row->facturado && $row->syear !== null, 'La CXC debe estar facturada y tener año académico.');
        $this->require(! $row->recibo, 'Esta CXC ya tiene recibo de pago y no admite nuevos cobros.');
        $remaining = ReceiptMoney::cents($row->parciales ?? $row->monto);
        $this->require($remaining >= 0 && $remaining <= ReceiptMoney::cents($row->monto), 'La CXC tiene un saldo inconsistente; requiere conciliación.');
        $balance = $this->platform()->table('public.saldo_pagos')->where('student_id', $row->student_id)->where('syear', $row->syear)->where('mes', (int) $row->item_pago)->first();
        $this->require($balance !== null, 'No existe el saldo académico del alumno, concepto y año.');
        $columns = self::fundingColumns($funding);
        $own = ReceiptMoney::cents($balance->{$columns[1]} ?? 0);
        $aggregate = 0;
        foreach (['saldo', 'saldo_beca', 'saldo_bienhechor'] as $column) {
            $aggregate += ReceiptMoney::cents($balance->{$column} ?? 0);
        }
        $this->require($remaining === $own || $remaining === $aggregate || $remaining === 0, 'CXC y saldos académicos no coinciden; requiere conciliación antes de cobrar.');

        $face = $remaining === $own ? ReceiptMoney::cents($row->monto) : min(ReceiptMoney::cents($row->monto), $own + ReceiptMoney::cents($balance->{$columns[0]} ?? 0));

        return ['cxc_id' => $row->id, 'student_id' => (string) $row->student_id, 'year' => (int) $row->syear, 'month' => (int) $row->item_pago,
            'funding' => $funding, 'balance_id' => $balance->id_saldo, 'amount' => ReceiptMoney::decimal($face), 'cxc_amount' => (string) $row->monto, 'external_remaining' => ReceiptMoney::decimal($remaining),
            'remaining' => ReceiptMoney::decimal(min($remaining, $own)), 'payer_name' => $row->custom_275, 'payer_email' => $row->custom_277,
            'account' => $row->cuenta_contable, 'kind' => 'academic'];
    }

    /** @return array{string, string} */
    public static function fundingColumns(string $funding): array
    {
        return match ($funding) {
            'normal' => ['pago', 'saldo'], 'beca' => ['pago_beca', 'saldo_beca'], 'bienhechor' => ['pago_bienhechor', 'saldo_bienhechor'],
            default => throw ValidationException::withMessages(['funding' => 'Responsable económico inválido.']),
        };
    }

    /**
     * @param  array{type: string, id: string, name?: string}  $recipient
     * @return list<array<string, mixed>>
     */
    public function debts(array $recipient, int $year, string $funding): array
    {
        if ($recipient['type'] === 'alumno') {
            return array_values($this->platform()->table('public.cxc')->where('student_id', $recipient['id'])->where('syear', $year)->where('facturado', true)
                ->where(function ($query): void {
                    $query->whereNull('recibo')->orWhere('recibo', false);
                })
                ->where(function ($q): void {
                    $q->where('parciales', '>', 0)->orWhere(function ($q): void {
                        $q->whereNull('parciales')->where('monto', '>', 0);
                    });
                })
                ->orderBy('item_pago')->get()->map(function ($row) use ($funding): array {
                    $key = 'academic:'.$row->id.':'.$funding;
                    $obligation = DB::table('libreria.receipt_obligations')->where('source_key', $key)->first();
                    try {
                        $data = $this->academicSource($row->id, $funding);
                        $error = null;
                    } catch (ValidationException $e) {
                        $data = ['remaining' => '0.00', 'payer_name' => $row->custom_275, 'payer_email' => $row->custom_277];
                        $error = collect($e->errors())->flatten()->implode(' ');
                    }

                    return ['key' => $key, 'name' => ((int) $row->item_pago === 1 ? 'Matrícula' : 'Cuota '.$row->item_pago).' · '.$row->syear,
                        'remaining' => $obligation->remaining ?? $data['remaining'], 'needsLink' => $obligation === null,
                        'error' => $error, 'payerName' => $data['payer_name'], 'payerEmail' => $data['payer_email'], 'cxcId' => $row->id, 'funding' => $funding, 'account' => $row->cuenta_contable];
                })->all());
        }
        $debts = DB::table('libreria.receipt_obligations')->where('recipient_type', $recipient['type'])->where('recipient_id', $recipient['id'])->where('remaining', '>', 0)
            ->orderBy('id')->get()->map(fn ($o) => ['key' => $o->source_key, 'name' => $this->data($o)['description'] ?? 'Factura pendiente', 'remaining' => $o->remaining, 'needsLink' => false, 'error' => null, 'account' => $this->data($o)['account'] ?? null])->all();
        if ($recipient['type'] === 'legacy_cliente') {
            foreach ($this->legacy()->table('public.saldo_cargos')->where('id_cliente', $recipient['id'])->where('saldo_pendiente', '>', 0)
                ->where(function ($q): void {
                    $q->whereNull('anulado')->orWhere('anulado', false);
                })->orderBy('id')->get() as $row) {
                if (! DB::table('libreria.receipt_obligations')->where('source_key', 'legacy:'.$row->id)->exists()) {
                    $debts[] = ['key' => 'legacy:'.$row->id, 'name' => 'CXC '.$row->uuid_venta, 'remaining' => (string) $row->saldo_pendiente, 'needsLink' => false, 'error' => null,
                        'account' => $this->legacy()->table('public.clientes')->where('id', $recipient['id'])->value('cta_cte')];
                }
            }
        }

        return array_values($debts);
    }

    public function linkAcademic(int $id, string $funding, string $origin, string $reference, int $userId, string $reason): int
    {
        $data = $this->academicSource($id, $funding);
        $invoice = $this->invoice($origin, $reference);
        $this->require(ReceiptMoney::cents($data['remaining']) > 0, 'Esta cuota no tiene saldo pendiente.');
        $this->require(ReceiptMoney::cents($data['amount']) <= ReceiptMoney::cents($invoice['amount']), 'El monto de la cuota excede la factura seleccionada.');
        if ($invoice['origin'] === 'native') {
            $registration = DB::table('libreria.receipt_credit_origins')->where('journal_id', $invoice['journal_id'])->first();
            $this->require($registration === null || $registration->applied_at !== null, 'Primero recupera el registro del crédito desde Conciliación de clientes y créditos.');
            $this->require(DB::table('libreria.venta_cuotas')->where('venta_id', DB::table('libreria.venta_dtes')->where('id', $invoice['journal_id'])->value('venta_id'))
                ->where('carnet_estudiante', $data['student_id'])->where('anio', $data['year'])->where('mes', $data['month'])->where('financiamiento', $funding)->exists(), 'El DTE no corresponde al alumno, año, concepto y responsable.');
        } else {
            $this->require($invoice['student_id'] === '' || $invoice['student_id'] === $data['student_id'], 'La factura pertenece a otro alumno.');
        }
        $data['invoice'] = $invoice;
        $data['description'] = ($data['month'] === 1 ? 'Matrícula' : 'Cuota '.$data['month']).' · '.$data['year'];
        $data['legacy_code'] = ReceiptCreditService::monthName($data['month']);
        $data['external_remaining'] = $data['remaining'];
        $data['cxc_opening'] = $this->platform()->table('public.cxc')->where('id', $id)->value('parciales') ?? $data['cxc_amount'];
        $data['applied_baseline'] = $this->appliedForSource($id);
        $data['invoice_historical_paid'] = ReceiptMoney::cents($data['amount']) - ReceiptMoney::cents($data['remaining']);
        $data['bank_paid'] = $this->bankPaid($data);
        $this->require(ReceiptMoney::cents($data['cxc_opening']) <= ReceiptMoney::cents($data['cxc_amount']) - $data['bank_paid'], 'Revisiones registra dinero ya recibido que no está descontado de CXC. Requiere conciliación.');

        return DB::transaction(function () use ($id, $funding, $data, $userId, $reason): int {
            $this->advisory('receipt-accounting');
            $this->advisory('academic:'.$id.':'.$funding);
            $this->require(! DB::table('libreria.receipt_obligations')->where('source_key', 'academic:'.$id.':'.$funding)->exists(), 'La obligación ya está vinculada; no se puede reemplazar su factura.');

            return $this->insertObligation('academic:'.$id.':'.$funding, 'alumno', $data['student_id'], $data, $userId, $reason);
        });
    }

    /** @param array{type: string, id: string, name?: string} $recipient */
    public function obligation(string $key, array $recipient): stdClass
    {
        $this->advisory($key);
        $row = DB::table('libreria.receipt_obligations')->where('source_key', $key)->lockForUpdate()->first();
        if ($row === null && preg_match('/^legacy:([0-9]+)$/', $key, $match)) {
            $cargo = $this->legacy()->table('public.saldo_cargos')->where('id', $match[1])->first();
            $this->require($cargo !== null && ! $cargo->anulado && $recipient['type'] === 'legacy_cliente' && (string) $cargo->id_cliente === $recipient['id'], 'El cargo no pertenece al destinatario.');
            $native = DB::table('libreria.venta_dtes')->where('codigo_generacion', $cargo->uuid_venta)->first();
            $invoice = $this->invoice($native === null ? 'legacy' : 'native', $cargo->uuid_venta);
            if ($native !== null) {
                $this->require(! DB::table('libreria.venta_cuotas')->where('venta_id', $native->venta_id)->exists(), 'Cobra la factura desde CXC de alumno para aplicar sus saldos académicos.');
            }
            $client = $this->legacy()->table('public.clientes')->where('id', $cargo->id_cliente)->first();
            $data = ['kind' => 'legacy', 'amount' => (string) $cargo->saldo, 'remaining' => (string) $cargo->saldo_pendiente,
                'external_remaining' => (string) $cargo->saldo_pendiente, 'legacy_cargo_id' => $cargo->id, 'legacy_client_id' => $cargo->id_cliente,
                'legacy_cargo_opening' => (string) $cargo->saldo_pendiente, 'cargo_applied_baseline' => $this->appliedForInvoice($invoice['uuid'], 'legacy'),
                'invoice' => $invoice, 'description' => 'Abono a '.$invoice['number'], 'legacy_code' => 'PAGO CXC '.$invoice['number'], 'account' => $client->cta_cte];
            $id = $this->insertObligation($key, $recipient['type'], $recipient['id'], $data);
            $row = DB::table('libreria.receipt_obligations')->where('id', $id)->lockForUpdate()->first();
        }
        $this->require($row !== null, 'La deuda requiere vinculación y conciliación antes de cobrar.');
        $this->require($row->recipient_type === $recipient['type'] && $row->recipient_id === $recipient['id'], 'La obligación no pertenece al destinatario.');
        $data = $this->data($row);
        $this->invoice($data['invoice']['origin'], $data['invoice']['uuid']);
        $this->verifyOutstanding($row, $data);

        return $row;
    }

    /** @param array<string, mixed> $data Historical obligation and invoice data. */
    public function insertObligation(string $key, string $type, string $recipientId, array $data, ?int $userId = null, ?string $reason = null): int
    {
        $invoice = $data['invoice'];
        $this->advisory('invoice:'.$invoice['uuid']);
        $budgetKey = 'invoice:'.$invoice['uuid'];
        $budget = DB::table('libreria.receipt_invoice_balances')->where('source_key', $budgetKey)->lockForUpdate()->first();
        $budgetData = $budget === null ? $invoice : $this->data($budget);
        $settled = 0;
        $initial = ReceiptMoney::cents($data['invoice_remaining'] ?? $invoice['amount']);
        if ($budget === null && $invoice['origin'] === 'legacy') {
            $cargos = $this->legacy()->table('public.saldo_cargos')->where('uuid_venta', $invoice['uuid'])
                ->where(function ($q): void {
                    $q->whereNull('anulado')->orWhere('anulado', false);
                })->get();
            $this->require($cargos->count() <= 1, 'La factura tiene cargos duplicados; requiere conciliación.');
            if ($cargos->isNotEmpty()) {
                $initial = ReceiptMoney::cents($cargos->first()->saldo_pendiente);
                $budgetData['cargo_id'] = $cargos->first()->id;
            }
        }
        if (isset($data['cxc_id']) && ! isset($budgetData['cargo_id']) && ! isset($data['invoice_remaining'])) {
            $groups = $budgetData['settled_cxc'] ?? [];
            $groupKey = $data['cxc_id'].':'.($data['funding'] ?? 'normal');
            if (! array_key_exists($groupKey, $groups) && ! array_key_exists((string) $data['cxc_id'], $groups)) {
                $settled = $data['invoice_historical_paid'] ?? (ReceiptMoney::cents($data['amount']) - ReceiptMoney::cents($data['cxc_opening']));
                $this->require($settled >= 0, 'Los pagos históricos de la CXC no concilian con su factura.');
                $groups[$groupKey] = $settled;
                $budgetData['settled_cxc'] = $groups;
            }
        }
        if ($budget === null) {
            $this->require($initial >= $settled, 'Los pagos históricos exceden el saldo de la factura; requiere conciliación.');
            $budgetId = DB::table('libreria.receipt_invoice_balances')->insertGetId(['source_key' => $budgetKey, 'invoice_uuid' => $invoice['uuid'],
                'amount' => $invoice['amount'], 'remaining' => ReceiptMoney::decimal($initial - $settled), 'data' => json_encode($budgetData), 'created_at' => now(), 'updated_at' => now()]);
        } else {
            $budgetId = $budget->id;
            $this->require(ReceiptMoney::cents($budget->remaining) >= $settled, 'La factura ya tiene cobros que requieren conciliación.');
            DB::table('libreria.receipt_invoice_balances')->where('id', $budgetId)->update(['remaining' => ReceiptMoney::decimal(ReceiptMoney::cents($budget->remaining) - $settled), 'data' => json_encode($budgetData), 'updated_at' => now()]);
        }

        return DB::table('libreria.receipt_obligations')->insertGetId(['source_key' => $key, 'invoice_balance_id' => $budgetId,
            'kind' => $data['kind'], 'recipient_type' => $type, 'recipient_id' => $recipientId, 'amount' => $data['amount'],
            'opening_remaining' => $data['remaining'], 'remaining' => $data['remaining'], 'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'linked_by' => $userId, 'link_reason' => $reason, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $data */
    public function verifyOutstanding(stdClass $row, array $data): void
    {
        if ($data['kind'] === 'academic') {
            $current = $this->academicSource($data['cxc_id'], $data['funding']);
            $difference = $this->appliedForSource($data['cxc_id']);
            $expected = ReceiptMoney::cents($data['cxc_opening']) - ($difference - ($data['applied_baseline'] ?? 0));
            $this->require(ReceiptMoney::cents($current['external_remaining']) === $expected, 'El saldo CXC cambió fuera del módulo; requiere conciliación.');
            $this->require($this->bankPaid($data) === ($data['bank_paid'] ?? 0), 'Se recibió otro pago en revisiones; concilia sus saldos antes de cobrar.');
        } elseif (isset($data['legacy_cargo_id'])) {
            $cargo = $this->legacy()->table('public.saldo_cargos')->where('id', $data['legacy_cargo_id'])->first();
            $this->verifyCargo($cargo, $data, $row->opening_remaining);
        }
    }

    /** @param array<string, mixed> $data */
    public function verifyCargo(?stdClass $cargo, array $data, string $opening): void
    {
        $difference = $this->appliedForInvoice($data['invoice']['uuid'], 'legacy') - ($data['cargo_applied_baseline'] ?? 0);
        $expected = ReceiptMoney::cents($data['legacy_cargo_opening'] ?? $opening) - $difference;
        $this->require($cargo !== null && ! $cargo->anulado && ReceiptMoney::cents($cargo->saldo_pendiente) === $expected, 'El saldo contable cambió fuera del módulo; requiere conciliación.');
    }

    public function appliedForInvoice(string $uuid, string $target): int
    {
        $sum = 0;
        foreach (DB::table('libreria.receipt_integrations as i')->join('libreria.receipt_lines as l', 'l.id', '=', 'i.line_id')
            ->where('l.data->invoice->uuid', $uuid)->where('i.target', $target)->where('i.status', 'APLICADO')->get(['i.operation', 'l.amount']) as $row) {
            $sum += ReceiptMoney::cents($row->amount) * ($row->operation === 'apply' ? 1 : -1);
        }

        return $sum;
    }

    /** @param array<string, mixed> $data */
    private function bankPaid(array $data): int
    {
        $sum = $this->platform()->table('public.revisiones')->where('student_id', $data['student_id'])->where('syear', $data['year'])->where('item_pago', $data['month'])
            ->where('facturado', true)->whereIn('numero_factura', [$data['invoice']['uuid'], $data['invoice']['number']])->sum('monto');

        return ReceiptMoney::cents($sum);
    }

    public function appliedForSource(int $cxcId): int
    {
        $sum = 0;
        foreach (DB::table('libreria.receipt_obligations')->where('data->cxc_id', $cxcId)->get() as $obligation) {
            $sum += $this->appliedForObligation($obligation->id, 'platform');
        }

        return $sum;
    }

    public function appliedForObligation(int $id, string $target): int
    {
        $sum = 0;
        $rows = DB::table('libreria.receipt_integrations as i')->join('libreria.receipt_lines as l', 'l.id', '=', 'i.line_id')
            ->where('l.obligation_id', $id)->where('i.target', $target)->where('i.status', 'APLICADO')->get(['i.operation', 'l.amount']);
        foreach ($rows as $row) {
            $sum += ReceiptMoney::cents($row->amount) * ($row->operation === 'apply' ? 1 : -1);
        }

        return $sum;
    }

    /** @return array<string, mixed> */
    public function data(stdClass $row): array
    {
        return json_decode($row->data, true, 512, JSON_THROW_ON_ERROR);
    }

    public function reconcile(string $key, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($key, $actorId, $reason): void {
            $this->advisory('receipt-accounting');
            $row = DB::table('libreria.receipt_obligations')->where('source_key', $key)->lockForUpdate()->firstOrFail();
            $data = $this->data($row);
            $pending = DB::table('libreria.receipt_integrations as i')->join('libreria.receipt_lines as l', 'l.id', '=', 'i.line_id')
                ->where('l.obligation_id', $row->id)->whereNotIn('i.status', ['APLICADO', 'CANCELADO'])->exists();
            $this->require(! $pending, 'Primero reintenta o anula los recibos con aplicaciones pendientes.');
            if ($row->kind === 'academic') {
                $current = $this->academicSource($data['cxc_id'], $data['funding']);
                $this->invoice($data['invoice']['origin'], $data['invoice']['uuid']);
                $data['cxc_opening'] = $current['external_remaining'];
                $data['applied_baseline'] = $this->appliedForSource($data['cxc_id']);
                $data['bank_paid'] = $this->bankPaid($data);
                $this->require(ReceiptMoney::cents($current['external_remaining']) <= ReceiptMoney::cents($current['cxc_amount']) - $data['bank_paid'], 'La CXC no refleja el pago que figura en revisiones. Corrige esa discrepancia antes de conciliar.');
                $remaining = ReceiptMoney::cents($current['remaining']);
                $opening = $remaining + $this->appliedForObligation($row->id, 'platform');
            } elseif ($row->kind === 'fotografia') {
                [, $column] = self::fundingColumns($data['funding']);
                $balance = $this->platform()->table('public.saldo_pagos')->where('id_saldo', $data['balance_id'])->firstOrFail();
                $remaining = ReceiptMoney::cents($balance->{$column} ?? 0);
                $opening = $remaining + $this->appliedForObligation($row->id, 'platform');
            } else {
                $cargo = $this->legacy()->table('public.saldo_cargos')->where('id', $data['legacy_cargo_id'])->firstOrFail();
                $this->require(! $cargo->anulado, 'La factura o cargo está anulado.');
                $remaining = ReceiptMoney::cents($cargo->saldo_pendiente);
                $data['legacy_cargo_opening'] = ReceiptMoney::decimal($remaining);
                $data['cargo_applied_baseline'] = $this->appliedForInvoice($data['invoice']['uuid'], 'legacy');
                $opening = $remaining + $this->appliedForObligation($row->id, 'legacy');
            }
            $this->require($remaining >= 0 && $remaining <= ReceiptMoney::cents($row->amount), 'El saldo supera la obligación original.');
            if ($row->invoice_balance_id !== null) {
                $budget = DB::table('libreria.receipt_invoice_balances')->where('id', $row->invoice_balance_id)->lockForUpdate()->firstOrFail();
                $next = ReceiptMoney::cents($budget->remaining) + $remaining - ReceiptMoney::cents($row->remaining);
                $this->require($next >= 0 && $next <= ReceiptMoney::cents($budget->amount), 'La factura compartida no concilia con el nuevo saldo.');
                DB::table('libreria.receipt_invoice_balances')->where('id', $budget->id)->update(['remaining' => ReceiptMoney::decimal($next), 'updated_at' => now()]);
            }
            $after = ['remaining' => ReceiptMoney::decimal($remaining), 'opening_remaining' => ReceiptMoney::decimal($opening), 'data' => json_encode($data), 'updated_at' => now()];
            DB::table('libreria.receipt_reconciliations')->insert(['obligation_id' => $row->id, 'user_id' => $actorId, 'reason' => $reason,
                'before' => json_encode((array) $row), 'after' => json_encode($after), 'created_at' => now()]);
            DB::table('libreria.receipt_obligations')->where('id', $row->id)->update($after);
        }, 3);
    }

    public function advisory(string $key): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('libreria.recibos'), hashtext(?))", [$key]);
        }
    }

    /** @phpstan-assert true $condition */
    public function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['receipts' => $message]);
        }
    }
}
