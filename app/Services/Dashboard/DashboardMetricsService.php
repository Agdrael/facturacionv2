<?php

namespace App\Services\Dashboard;

use App\Http\Middleware\ResolvePuntoVentaById;
use App\Models\User;
use App\Services\Libreria\CashMovementCalculator;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DashboardMetricsService
{
    public const REGISTERS = ['P001', 'P002', 'P003', 'P004'];

    public function __construct(private CashMovementCalculator $calculator, private ResolvePuntoVentaById $points) {}

    public function mode(User $user): string
    {
        if (! $user->can('libreria.cajas.ver')) {
            return 'welcome';
        }

        return $user->hasRole(['admin', 'contador']) && $user->can('libreria.cajas.ver_todas') ? 'executive' : 'cashier';
    }

    public function assigned(): string
    {
        return $this->points->resolve(request());
    }

    /** @return array<string, mixed> */
    public function query(User $user, string $from, string $to, string $register = 'all'): array
    {
        $mode = $this->mode($user);
        if ($mode === 'welcome') {
            return ['mode' => $mode, 'updatedAt' => now(config('cash.timezone'))->format('d/m/Y H:i:s')];
        }
        $today = now(config('cash.timezone'))->toDateString();
        Validator::make(['from' => $from, 'to' => $to, 'register' => $register], [
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:to'],
            'to' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'register' => ['required', 'in:all,'.implode(',', self::REGISTERS)],
        ])->validate();
        $assigned = $this->assigned();
        if ($mode === 'cashier') {
            abort_unless($from === $today && $to === $today && ($register === 'all' || $register === $assigned), 403);
            $register = $assigned;
        }
        $registers = $register === 'all' ? self::REGISTERS : [$register];
        $totals = new DashboardTotals($registers);
        $this->nativeSales($totals, $registers, $from, $to);
        $receiptAvailable = Schema::hasTable('libreria.receipts');
        if ($receiptAvailable) {
            $this->nativeReceipts($totals, $registers, $from, $to);
        }
        $coverage = $this->legacyCoverage($registers, $from);
        if (Schema::hasTable('public.ventas') && Schema::hasTable('public.recibos') && Schema::hasTable('public.libreria_recibo_documentos')) {
            foreach ($registers as $point) {
                if ($coverage[$point]['from'] === null) {
                    continue;
                }
                $start = $point === 'P004' ? max($from, $coverage[$point]['from']) : $from;
                if ($start <= $to) {
                    $this->legacySales($totals, $point, $start, $to);
                    $this->legacyReceipts($totals, $point, $start, $to);
                }
            }
        } else {
            foreach ($coverage as &$entry) {
                $entry['message'] = 'V1 no disponible en esta base.';
                $entry['partial'] = true;
            }
            unset($entry);
        }
        $data = $totals->result($from, $to);
        $states = $this->states($registers);
        foreach ($data['boxes'] as $point => &$box) {
            $box['register'] = $point;
            $box['state'] = $states[$point];
            $box['coverage'] = $coverage[$point];
        }
        unset($box);

        return [...$data, 'mode' => $mode, 'from' => $from, 'to' => $to, 'register' => $register,
            'assigned' => $assigned, 'coverage' => $coverage, 'receiptAvailable' => $receiptAvailable,
            'openV2' => count(array_filter($states, fn ($state): bool => $state['v2'] === 'Abierta')),
            'openV1' => count(array_filter($states, fn ($state): bool => $state['v1'] === 'Abierta')),
            'updatedAt' => now(config('cash.timezone'))->format('d/m/Y H:i:s')];
    }

    /** @param list<string> $registers */
    private function nativeSales(DashboardTotals $totals, array $registers, string $from, string $to): void
    {
        DB::table('libreria.ventas as v')->join('libreria.venta_dtes as d', 'd.venta_id', '=', 'v.id')
            ->leftJoin('public.users as u', 'u.id', '=', 'd.user_id')->whereIn('v.caja', $registers)->whereBetween('v.fecha', [$from, $to])
            ->select('v.id', 'v.caja', 'v.fecha', 'v.created_at', 'v.total', 'v.operacion', 'v.tipo_documento', 'v.estado',
                'd.numero_control', 'd.pagos', 'd.transmision_estado', 'u.name')
            ->chunkById(500, function (Collection $rows) use ($totals): void {
                foreach ($rows as $row) {
                    $event = $this->event('v2', 'sale', $row->id, $row->caja, $row->fecha, $row->numero_control, $row->transmision_estado,
                        $row->name ?? 'Usuario eliminado', $row->created_at);
                    $event['type'] = $row->tipo_documento;
                    $this->collect($totals, $event, $row->estado === 'ANULADO', $row->total, function () use ($row): array {
                        $payments = json_decode($row->pagos, true, 512, JSON_THROW_ON_ERROR);
                        $this->calculator->require(is_array($payments) && isset($payments['received']) && is_array($payments['received']), 'El DTE no conserva un detalle de pagos válido.');
                        $this->calculator->require(in_array($row->tipo_documento, ['01', '03'], true) && in_array($row->operacion, ['1', '2', '3'], true), 'El DTE no identifica un tipo de documento u operación válidos.');
                        $money = $this->calculator->native($payments['received'], (string) ($payments['change'] ?? '0'), (string) $row->total, (string) $row->operacion);
                        $paid = $this->calculator->cents($money['paid']);
                        $total = $this->calculator->cents($money['total']);
                        $this->calculator->require($paid <= $total && ($row->operacion === '2' || $paid === $total), 'Los pagos del DTE no concuerdan con la operación y su total.');

                        return $money;
                    });
                }
            }, 'v.id', 'id');
    }

    /** @param list<string> $registers */
    private function nativeReceipts(DashboardTotals $totals, array $registers, string $from, string $to): void
    {
        $start = CarbonImmutable::parse($from, config('cash.timezone'))->startOfDay();
        $end = CarbonImmutable::parse($to, config('cash.timezone'))->addDay()->startOfDay();
        DB::table('libreria.receipts')->whereIn('register', $registers)->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->select('id', 'register', 'created_at', 'total', 'change', 'status', 'operator_name')
            ->chunkById(500, function (Collection $rows) use ($totals): void {
                $payments = DB::table('libreria.receipt_payments')->whereIn('receipt_id', $rows->pluck('id'))->get()->groupBy('receipt_id');
                foreach ($rows as $row) {
                    $date = CarbonImmutable::parse($row->created_at)->setTimezone(config('cash.timezone'))->toDateString();
                    $event = $this->event('v2', 'receipt', $row->id, $row->register, $date, 'R-'.str_pad((string) $row->id, 8, '0', STR_PAD_LEFT),
                        $row->status, $row->operator_name, $row->created_at);
                    $this->collect($totals, $event, $row->status === 'ANULADO', $row->total, function () use ($payments, $row): array {
                        $received = $payments->get($row->id, collect())->map(fn ($payment): array => ['method' => (string) $payment->method, 'amount' => (string) $payment->amount])->all();
                        $money = $this->calculator->native($received, (string) $row->change, (string) $row->total);
                        $this->calculator->require($this->calculator->cents($money['paid']) === $this->calculator->cents($money['total']), 'Los pagos del recibo no coinciden con su total.');

                        return $money;
                    });
                }
            });
    }

    /** @return array<string, mixed> */
    private function event(string $source, string $kind, int $id, string $register, string $date, string $number, string $state, string $operator, ?string $stamp = null): array
    {
        try {
            $time = $stamp === null ? null : CarbonImmutable::parse($stamp)->setTimezone(config('cash.timezone'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            $time = null;
        }

        return compact('source', 'kind', 'id', 'register', 'date', 'number', 'state', 'operator')
            + ['stamp' => $time ?? $date.' 00:00:00', 'timeKnown' => $time !== null];
    }

    /** @param array<string, mixed> $event */
    private function collect(DashboardTotals $totals, array $event, bool $annulled, mixed $amount, \Closure $normalize): void
    {
        if ($annulled) {
            try {
                $cents = $this->calculator->cents($amount);
            } catch (\Throwable) {
                $cents = null;
            }
            $totals->annul($event, $cents);

            return;
        }
        try {
            $totals->add($event, $normalize());
        } catch (ValidationException $error) {
            $totals->issue($event, implode(' ', array_merge(...array_values($error->errors()))));
        } catch (\JsonException|MathException $error) {
            $totals->issue($event, 'Los importes o pagos guardados no tienen un formato válido.');
        }
    }

    /** @param list<string> $registers
     * @return array<string, array<string, mixed>> */
    private function legacyCoverage(array $registers, string $from): array
    {
        $first = collect();
        $active = collect();
        if (Schema::hasTable('libreria.legacy_cash_audits')) {
            $first = DB::table('libreria.legacy_cash_audits')->where('action', 'activate')->selectRaw('register, MIN(created_at) as started')->groupBy('register')->get()->keyBy('register');
        }
        if (Schema::hasTable('libreria.legacy_cash_registers')) {
            $active = DB::table('libreria.legacy_cash_registers')->pluck('active', 'register');
        }
        $global = $first->min('started');
        $coverage = [];
        foreach ($registers as $point) {
            $stamp = $point === 'P004' ? $global : ($first->get($point)->started ?? null);
            $date = $stamp === null ? null : CarbonImmutable::parse($stamp)->setTimezone(config('cash.timezone'))->toDateString();
            $enabled = $point === 'P004' ? $date !== null : (bool) $active->get($point, false);
            $coverage[$point] = ['from' => $date, 'active' => $enabled, 'partial' => $date === null || $from < $date || ! $enabled,
                'message' => $date === null ? 'Sin integración V1 registrada.' : 'V1 desde '.CarbonImmutable::parse($date)->format('d/m/Y')
                    .($point === 'P004' ? ' · por fecha, sin sesión.' : ($enabled ? ' · movimientos identificados.' : ' · integración desactivada.'))];
        }

        return $coverage;
    }

    private function legacyBase(string $table, string $alias, string $kind, string $point, string $from, string $to): Builder
    {
        $id = $kind === 'venta' ? 'id_venta' : 'id';
        $query = DB::table($table.' as '.$alias)->whereBetween($alias.'.fecha_sistema', [$from, $to]);
        if ($point === 'P004') {
            $query->select($alias.'.*');
        } else {
            $query->join('libreria.legacy_cash_movements as m', function ($join) use ($alias, $id, $kind): void {
                $join->on('m.legacy_id', '=', $alias.'.'.$id)->where('m.kind', $kind);
            })->where('m.register', $point)->where('m.excluded', false)
                ->select($alias.'.*', 'm.legacy_session_id', 'm.recorded_at', 'm.confirmed_amount', 'm.confirmed_method');
        }

        return $query;
    }

    private function legacySales(DashboardTotals $totals, string $point, string $from, string $to): void
    {
        $this->legacyBase('public.ventas', 'v', 'venta', $point, $from, $to)
            ->where('v.numero_control', 'like', '%'.$point.'-%')
            ->where(fn ($query) => $query->whereNull('v.operacion')->orWhere('v.operacion', '!=', '05'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('libreria.venta_dtes as d')
                ->whereRaw('LOWER(CAST(d.codigo_generacion AS TEXT)) = LOWER(TRIM(CAST(v.numero_factura AS TEXT)))'))
            ->chunkById(500, function (Collection $rows) use ($totals, $point): void {
                $payments = DB::table('public.metodos_pago_venta')->whereIn('id_venta', $rows->pluck('id_venta'))->get()->groupBy('id_venta');
                $numbers = DB::table('libreria.venta_dtes')->whereIn('numero_control', $rows->pluck('numero_control'))->pluck('numero_control')->all();
                $uuidExpression = 'LOWER(TRIM(CAST(numero_factura AS TEXT)))';
                $duplicates = DB::table('public.ventas')->whereIn(DB::raw($uuidExpression), $rows->pluck('numero_factura')->map(fn ($uuid): string => strtolower(trim((string) $uuid)))->all())
                    ->where(fn ($query) => $query->whereNull('anulado')->orWhere('anulado', false))->where('operacion', '!=', '05')
                    ->selectRaw($uuidExpression.' as uuid')->groupByRaw($uuidExpression)->havingRaw('COUNT(*) > 1')->pluck('uuid')->all();
                $duplicateNumbers = DB::table('public.ventas')->whereIn('numero_control', $rows->pluck('numero_control'))
                    ->where(fn ($query) => $query->whereNull('anulado')->orWhere('anulado', false))->where('operacion', '!=', '05')
                    ->select('numero_control')->groupBy('numero_control')->havingRaw('COUNT(*) > 1')->pluck('numero_control')->all();
                foreach ($rows as $row) {
                    $event = $this->event('v1', 'sale', $row->id_venta, $point, $row->fecha_sistema, $row->numero_control,
                        empty($row->sello_recepcion) ? 'SIN_CONFIRMAR' : 'ACEPTADO', 'Operador V1 #'.$row->id_usuario,
                        $row->recorded_at ?? ($row->hora ? $row->fecha_sistema.' '.substr($row->hora, 0, 8) : null));
                    $event['type'] = explode('-', $row->numero_control)[1] ?? '';
                    $this->collect($totals, $event, (bool) $row->anulado, $row->total, function () use ($row, $payments, $numbers, $duplicates, $duplicateNumbers, $point): array {
                        $this->calculator->require($point === 'P004' || $row->legacy_session_id !== null, 'Confirma el turno V1 en la conciliación inicial.');
                        $this->calculator->require(! in_array($row->numero_control, $numbers, true) && ! in_array($row->numero_control, $duplicateNumbers, true)
                            && ! in_array(strtolower(trim((string) $row->numero_factura)), $duplicates, true), 'Hay un UUID o número de control duplicado.');
                        $this->calculator->require(! empty($row->numero_factura) && (bool) preg_match('/^DTE-(01|03)-[A-Z0-9]{4}'.preg_quote($point, '/').'-[0-9]+$/', $row->numero_control), 'La factura V1 no identifica documento y caja válidos.');

                        return $this->calculator->legacySale($row, $payments->get($row->id_venta, collect()));
                    });
                }
            }, 'v.id_venta', 'id_venta');
    }

    private function legacyReceipts(DashboardTotals $totals, string $point, string $from, string $to): void
    {
        $this->legacyBase('public.recibos', 'r', 'recibo', $point, $from, $to)->where('r.numero_caja', $point)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('public.libreria_recibo_documentos as d')->whereColumn('d.legacy_receipt_id', 'r.id'))
            ->chunkById(500, function (Collection $rows) use ($totals, $point): void {
                foreach ($rows as $row) {
                    $event = $this->event('v1', 'receipt', $row->id, $point, $row->fecha_sistema, 'V1 #'.$row->id, 'EMITIDO', 'No registrado en V1', $row->recorded_at ?? null);
                    $this->collect($totals, $event, (bool) $row->anulado, $row->precio ?? $row->confirmed_amount ?? null, function () use ($row, $point): array {
                        $this->calculator->require($point === 'P004' || $row->legacy_session_id !== null, 'Confirma el turno V1 en la conciliación inicial.');

                        return $this->calculator->legacyReceipt($row, $point === 'P004' ? null : $row);
                    });
                }
            }, 'r.id', 'id');
    }

    /** @param list<string> $registers
     * @return array<string, array<string, mixed>> */
    private function states(array $registers): array
    {
        $open = DB::table('libreria.caja_sesiones as s')->join('libreria.cajas as c', 'c.id', '=', 's.caja_id')
            ->whereIn('c.codigo', $registers)->whereNull('s.cerrado_at')->get(['c.codigo', 's.abierto_at', 's.abierto_nombre'])->keyBy('codigo');
        $legacy = Schema::hasTable('public.cajas') ? DB::table('public.cajas')->whereIn('codigo_caja', $registers)->get()->groupBy('codigo_caja') : collect();
        $states = [];
        foreach ($registers as $register) {
            $boxes = $legacy->get($register, collect());
            $states[$register] = ['v2' => $register === 'P004' ? 'Sin control de apertura' : ($open->has($register) ? 'Abierta' : 'Cerrada'),
                'v1' => $register === 'P004' ? 'Sin control de apertura' : ($boxes->count() !== 1 ? 'No verificable' : ($boxes->first()->estado_caja ? 'Abierta' : 'Cerrada')),
                'openedBy' => $open->get($register)->abierto_nombre ?? null,
                'openedAt' => isset($open[$register]) ? CarbonImmutable::parse($open[$register]->abierto_at)->setTimezone(config('cash.timezone'))->format('d/m/Y H:i') : null];
        }

        return $states;
    }
}
