<?php

namespace App\Services\Libreria;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiptSelectionService
{
    public function __construct(private ReceiptSourceService $sources) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{recipient: array{type: string, id: string, name: string}, name: string, remaining: mixed, account: ?string, invoice: ?array<string, mixed>, invoices: list<array<string, mixed>>, studentId?: string, year?: int, funding?: string, payerName?: ?string}
     */
    public function describe(array $input): array
    {
        if ($input['mode'] === 'academic_cxc') {
            $source = $this->sources->academicSource((int) $input['sourceId'], $input['funding'] ?? 'normal');
            $key = 'academic:'.$source['cxc_id'].':'.$source['funding'];
            $obligation = DB::table('libreria.receipt_obligations')->where('source_key', $key)->first();
            $invoices = $this->academicInvoices($source, trim($input['q'] ?? ''));
            $selected = $obligation === null ? null : $this->sources->data($obligation)['invoice'];
            if (isset($input['invoice'])) {
                $selected = $this->sources->invoice($input['invoice']['origin'], $input['invoice']['uuid']);
            }
            if ($selected === null && count($invoices) === 1 && ! $invoices[0]['confirmPeriod']) {
                $selected = $invoices[0];
            }

            return ['recipient' => $this->sources->recipient(['type' => 'alumno', 'id' => $source['student_id']]),
                'name' => ReceiptCreditService::monthName($source['month']).' · '.$source['year'],
                'remaining' => $obligation->remaining ?? $source['remaining'], 'account' => $this->academicAccount($source['student_id'], $source['year']),
                'studentId' => $source['student_id'], 'year' => $source['year'], 'funding' => $source['funding'],
                'payerName' => $source['payer_name'], 'invoice' => $selected, 'invoices' => $invoices];
        }
        $key = $input['sourceKey'] ?? '';
        if (preg_match('/^legacy:([0-9]+)$/', $key, $match)) {
            $cargo = $this->sources->legacy()->table('public.saldo_cargos')->where('id', $match[1])->first();
            $this->sources->require($cargo !== null && ! $cargo->anulado && ReceiptMoney::cents($cargo->saldo_pendiente) > 0, 'La cuenta por cobrar no está pendiente.');
            $native = DB::table('libreria.venta_dtes')->where('codigo_generacion', $cargo->uuid_venta)->first();
            $invoice = $this->sources->invoice($native === null ? 'legacy' : 'native', $cargo->uuid_venta);
            if ($native !== null) {
                $this->sources->require(! DB::table('libreria.venta_cuotas')->where('venta_id', $native->venta_id)->exists(), 'Esta factura se cobra desde CXC de alumno para aplicar sus saldos académicos.');
            } else {
                $academic = $this->sources->legacy()->table('public.productos_vendidos as p')->join('public.ventas as v', 'v.id_venta', '=', 'p.id_ventas')
                    ->where('v.numero_factura', $cargo->uuid_venta)->where(function ($q): void {
                        $q->whereRaw('LOWER(p.nombre_producto) LIKE ?', ['%colegiatura%'])->orWhereRaw('LOWER(p.nombre_producto) LIKE ?', ['matr%']);
                    })->exists();
                $this->sources->require(! $academic, 'Esta factura se cobra desde CXC de alumno para aplicar sus saldos académicos.');
            }
            $client = $this->sources->legacy()->table('public.clientes')->where('id', $cargo->id_cliente)->firstOrFail();

            return ['recipient' => ['type' => 'legacy_cliente', 'id' => (string) $client->id, 'name' => $client->nombre],
                'name' => 'Abono a '.$invoice['number'], 'remaining' => (string) $cargo->saldo_pendiente, 'account' => $client->cta_cte,
                'invoice' => $invoice, 'invoices' => [$invoice]];
        }
        $obligation = DB::table('libreria.receipt_obligations')->where('source_key', $key)->first();
        $this->sources->require($obligation !== null && $obligation->recipient_type !== 'alumno' && ReceiptMoney::cents($obligation->remaining) > 0, 'Selecciona una factura pendiente de cliente.');
        $source = $this->sources->data($obligation);

        return ['recipient' => $this->sources->recipient(['type' => $obligation->recipient_type, 'id' => $obligation->recipient_id]),
            'name' => $source['description'], 'remaining' => $obligation->remaining, 'account' => $source['account'],
            'invoice' => $this->sources->invoice($source['invoice']['origin'], $source['invoice']['uuid']), 'invoices' => [$source['invoice']]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{recipient: array{type: string, id: string, name: string}, line: array<string, mixed>}
     */
    public function resolve(array $input, int $actorId): array
    {
        $selection = $this->describe($input);
        $this->sources->require(! isset($input['invoice']) || filter_var($input['invoice']['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN), 'Confirma que la factura corresponde al alumno, concepto y año seleccionados.');
        $invoice = $selection['invoice'];
        $this->sources->require($invoice !== null, 'Selecciona la factura correspondiente y confirma el período antes de cobrar.');
        $account = $input['account'] ?? $selection['account'];
        $this->sources->require(! empty($account), 'No existe una cuenta CXC para la matrícula seleccionada. Selecciona una cuenta válida antes de cobrar.');
        if ($input['mode'] === 'academic_cxc') {
            $this->sources->require(isset($selection['year']), 'La obligación debe tener un año académico confirmado.');
            $key = 'academic:'.$input['sourceId'].':'.($input['funding'] ?? 'normal');
            $existing = DB::table('libreria.receipt_obligations')->where('source_key', $key)->first();
            if ($existing === null) {
                $this->sources->linkAcademic((int) $input['sourceId'], $input['funding'] ?? 'normal', $invoice['origin'], $invoice['uuid'], $actorId,
                    'Factura resuelta al emitir el recibo; período '.$selection['year'].(isset($input['invoice']) ? ' confirmado por el operador.' : ' verificado automáticamente.'));
            } else {
                $this->sources->require($this->sources->data($existing)['invoice']['uuid'] === $invoice['uuid'], 'La CXC ya tiene otra factura de origen.');
            }
        } else {
            $key = $input['sourceKey'];
        }

        return ['recipient' => $selection['recipient'], 'line' => ['obligationKey' => $key, 'amount' => $input['amount'], 'account' => $account]];
    }

    public function academicAccount(string $studentId, int $year): ?string
    {
        $rows = $this->sources->platform()->table('public.student_enrollment as e')->join('public.school_gradelevels as g', 'g.id', '=', 'e.grade_id')
            ->where('e.student_id', $studentId)->where('e.syear', $year)->get(['g.title', 'e.especialidad']);
        if ($rows->count() !== 1) {
            return null;
        }
        $row = $rows->first();
        $grade = ['1er. Año' => '1°', '2° Año' => '2°', '3er. Año' => '3°'][$row->title] ?? $row->title;
        $specialty = ['' => 'cxc', 'Sistemas Informáticos' => 'Info cxc', 'Desarrollo de Software' => 'Info cxc',
            'Bachillerato General' => 'General/Salud cxc', 'Salud' => 'General/Salud cxc', 'Electrónica' => 'Electronica cxc',
            'Diseño Gráfico' => 'Diseño cxc', 'Electromecánica' => 'Electromecanica cxc'][$row->especialidad ?? ''] ?? null;
        if ($specialty === null) {
            return null;
        }
        $accounts = $this->sources->legacy()->table('public.cuentas_cargo_reporte')->where('nombre', trim($grade.' '.$specialty))->pluck('llave');
        if ($accounts->count() !== 1) {
            return null;
        }
        $account = $accounts->first();

        return DB::table('public.catalogo_contabilidad')->where('cuenta', $account)->where('cuenta', 'like', '1102%')->where('terminal', 'T')->exists() ? $account : null;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function academicInvoices(array $source, string $search): array
    {
        $result = [];
        $native = DB::table('libreria.venta_dtes as d')->join('libreria.venta_cuotas as q', 'q.venta_id', '=', 'd.venta_id')
            ->where('q.carnet_estudiante', $source['student_id'])->where('q.anio', $source['year'])->where('q.mes', $source['month'])->where('q.financiamiento', $source['funding'])
            ->distinct()->limit(30)->pluck('d.codigo_generacion');
        foreach ($native as $uuid) {
            $this->candidate($result, 'native', $uuid, false, $search);
        }
        $references = $this->sources->platform()->table('public.revisiones')->where('student_id', $source['student_id'])->where('syear', $source['year'])
            ->where('item_pago', $source['month'])->where('facturado', true)->whereNotNull('numero_factura')->distinct()->pluck('numero_factura')->all();
        $legacy = $this->sources->legacy()->table('public.ventas as v')->where('v.operacion', '2')->where(function ($q): void {
            $q->whereNull('v.anulado')->orWhere('v.anulado', false);
        });
        if ($search !== '') {
            $legacy->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(v.numero_factura) LIKE ?', ['%'.mb_strtolower($search).'%'])->orWhereRaw('LOWER(v.numero_control) LIKE ?', ['%'.mb_strtolower($search).'%']);
            })
                ->where(function ($q) use ($source): void {
                    $q->where('v.n_carnet', $source['student_id'])->orWhereNull('v.n_carnet')->orWhere('v.n_carnet', '');
                });
        } else {
            $legacy->where(function ($q) use ($source, $references): void {
                $q->whereIn('v.numero_factura', $references)->orWhereIn('v.numero_control', $references)
                    ->orWhere(function ($q) use ($source): void {
                        $q->where('v.n_carnet', $source['student_id'])->where('v.total', '>=', $source['amount'])
                            ->whereExists(function ($p) use ($source): void {
                                $p->selectRaw('1')->from('public.productos_vendidos as p')->whereColumn('p.id_ventas', 'v.id_venta');
                                $this->whereAcademicProduct($p, $source['month']);
                            });
                    });
            });
        }
        foreach ($legacy->orderBy('v.id_venta')->limit(30)->get(['v.numero_factura', 'v.numero_control']) as $row) {
            $verified = in_array($row->numero_factura, $references, true) || in_array($row->numero_control, $references, true);
            if (! $verified) {
                $productsQuery = $this->sources->legacy()->table('public.productos_vendidos as p')->join('public.ventas as v', 'v.id_venta', '=', 'p.id_ventas')
                    ->where('v.numero_factura', $row->numero_factura)->where('v.n_carnet', $source['student_id']);
                $this->whereAcademicProduct($productsQuery, $source['month']);
                $products = $productsQuery->pluck('p.nombre_producto');
                $verified = $products->contains(fn ($name) => preg_match('/\b'.preg_quote((string) $source['year'], '/').'\b/u', $name) === 1);
            }
            $this->candidate($result, 'legacy', $row->numero_factura, ! $verified || $source['funding'] !== 'normal', $search);
        }

        return array_values($result);
    }

    /** @param array<string, array<string, mixed>> $result */
    private function candidate(array &$result, string $origin, string $uuid, bool $confirm, string $search): void
    {
        try {
            $invoice = $this->sources->invoice($origin, $uuid);
        } catch (ValidationException) {
            return;
        }
        if ($search !== '' && ! str_contains(mb_strtolower($invoice['number'].' '.$uuid), mb_strtolower($search))) {
            return;
        }
        if ($origin === 'legacy' && isset($result[$uuid])) {
            return;
        }
        $result[$uuid] = $invoice + ['confirmPeriod' => $confirm];
    }

    private function whereAcademicProduct(Builder $query, int $month): void
    {
        $query->where(function ($query) use ($month): void {
            if ($month === 1) {
                $query->whereRaw('LOWER(p.nombre_producto) LIKE ?', ['%matr%']);
            } else {
                $query->whereRaw('LOWER(p.nombre_producto) LIKE ?', ['%colegiatura%'.mb_strtolower(ReceiptCreditService::monthName($month)).'%'])
                    ->orWhereRaw('LOWER(p.nombre_producto) LIKE ?', ['%colegiatura 0250'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'%']);
            }
        });
    }
}
