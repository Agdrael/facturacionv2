<?php

namespace App\Services\Libreria;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

class AcademicPaymentService
{
    public function apply(stdClass $journal): void
    {
        if (DB::table('libreria.venta_invalidaciones')->where('venta_dte_id', $journal->id)->exists()) {
            throw new RuntimeException('El DTE tiene una solicitud de invalidación; no se pueden aplicar pagos.');
        }
        $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
        if ($journal->transmision_estado !== 'ACEPTADO' || empty($journal->sello_recepcion) || $document['identificacion']['ambiente'] !== '01') {
            throw new RuntimeException('Solo se aplican pagos con aceptación productiva y sello de recepción.');
        }
        if ($journal->pagos_aplicados_at !== null) {
            return;
        }
        $claims = DB::table('libreria.venta_cuotas')->where('venta_id', $journal->venta_id)->orderBy('saldo_id')->get();
        if ($claims->isNotEmpty() && ($document['resumen']['condicionOperacion'] ?? null) === 2) {
            throw new RuntimeException('Las colegiaturas a crédito requieren el proceso de Cuentas por cobrar antes de aplicar saldos.');
        }
        if ($claims->isNotEmpty()) {
            $platform = DB::connection('plataforma');
            $platform->transaction(function () use ($platform, $claims, $journal, $document): void {
                if ($platform->getDriverName() === 'pgsql') {
                    $platform->select("SELECT pg_advisory_xact_lock(hashtext('libreria.aplicar_pago'), hashtext(?))", [$journal->codigo_generacion]);
                }
                $balances = $platform->table('saldo_pagos')->whereIn('id_saldo', $claims->pluck('saldo_id')->all())->orderBy('id_saldo')->lockForUpdate()->get()->keyBy('id_saldo');
                $seen = [];
                foreach ($claims as $claim) {
                    if ($claim->ambiente !== '01') {
                        throw new RuntimeException('Una referencia de pruebas no puede aplicarse a saldos productivos.');
                    }
                    $key = $claim->carnet_estudiante.':'.$claim->anio.':'.$claim->mes.':'.$claim->financiamiento;
                    if (isset($seen[$key])) {
                        throw new RuntimeException('Hay cuotas duplicadas del mismo alumno, mes y financiamiento.');
                    }
                    $seen[$key] = true;
                    [$paidColumn,$balanceColumn] = match ($claim->financiamiento) {
                        'normal' => ['pago', 'saldo'], 'beca' => ['pago_beca', 'saldo_beca'], 'bienhechor' => ['pago_bienhechor', 'saldo_bienhechor'],
                        default => throw new RuntimeException('Financiamiento desconocido.'),
                    };
                    $existing = $platform->table('detalle_pagos')->where('uid_dte', $journal->codigo_generacion)
                        ->where('student_id', $claim->carnet_estudiante)->where('syear', $claim->anio)->where('mes_pagado', $claim->mes);
                    foreach (['pago_beca' => $claim->financiamiento === 'beca', 'pago_bienhechor' => $claim->financiamiento === 'bienhechor'] as $column => $flag) {
                        $existing->where(function ($query) use ($column, $flag): void {
                            $query->where($column, $flag);
                            if (! $flag) {
                                $query->orWhereNull($column);
                            }
                        });
                    }
                    $previous = $existing->get(['monto', 'numero_dte', 'facturado']);
                    $amount = BigDecimal::of((string) $claim->monto)->toScale(2, RoundingMode::HalfUp);
                    if ($previous->isNotEmpty()) {
                        if ($previous->count() !== 1 || ! BigDecimal::of((string) $previous->first()->monto)->toScale(2, RoundingMode::HalfUp)->isEqualTo($amount) || $previous->first()->numero_dte !== $journal->numero_control || ! $previous->first()->facturado) {
                            throw new RuntimeException('El detalle de pago existente no coincide con el DTE confirmado.');
                        }

                        continue;
                    }
                    $balance = $balances->get($claim->saldo_id);
                    if ($balance === null || (string) $balance->student_id !== (string) $claim->carnet_estudiante || (int) $balance->syear !== (int) $claim->anio || (int) $balance->mes !== (int) $claim->mes || ! $amount->isGreaterThan(0)) {
                        throw new RuntimeException('La referencia de la cuota ya no coincide con la plataforma.');
                    }
                    $remaining = BigDecimal::of((string) ($balance->{$balanceColumn} ?? 0))->toScale(2, RoundingMode::HalfUp);
                    if ($remaining->isLessThan($amount)) {
                        throw new RuntimeException('El saldo de la cuota cambió. Requiere conciliación antes de aplicar el DTE.');
                    }
                    $platform->table('saldo_pagos')->where('id_saldo', $claim->saldo_id)->update([
                        $paidColumn => (string) BigDecimal::of((string) ($balance->{$paidColumn} ?? 0))->toScale(2, RoundingMode::HalfUp)->plus($amount),
                        $balanceColumn => (string) $remaining->minus($amount), 'fecha_actualizado' => DB::raw('CURRENT_TIMESTAMP'), 'habilitado' => 1,
                    ]);
                    $platform->table('detalle_pagos')->insert([
                        'student_id' => $claim->carnet_estudiante, 'mes_pagado' => $claim->mes, 'fecha' => now('America/El_Salvador')->toDateString(),
                        'monto' => (string) $amount, 'lugar' => 'colecturia', 'syear' => $claim->anio,
                        'uid_dte' => $journal->codigo_generacion, 'fecha_emision_dte' => $document['identificacion']['fecEmi'],
                        'numero_dte' => $journal->numero_control, 'facturado' => true,
                        'pago_beca' => $claim->financiamiento === 'beca', 'pago_bienhechor' => $claim->financiamiento === 'bienhechor',
                    ]);
                }
            }, 3);
        }
        DB::transaction(function () use ($journal): void {
            DB::table('libreria.venta_dtes')->where('id', $journal->id)->update(['pagos_aplicados_at' => now(), 'transmision_error' => null, 'updated_at' => now()]);
            DB::table('libreria.ventas')->where('id', $journal->venta_id)->update(['estado' => 'FACTURADO', 'updated_at' => now()]);
        });
    }

    public function reverse(stdClass $journal): void
    {
        $document = json_decode($journal->documento, true, 512, JSON_THROW_ON_ERROR);
        if ($document['identificacion']['ambiente'] === '00') {
            return;
        }
        $claims = DB::table('libreria.venta_cuotas')->where('venta_id', $journal->venta_id)->orderBy('saldo_id')->get();
        if ($claims->isEmpty()) {
            return;
        }
        $platform = DB::connection('plataforma');
        $platform->transaction(function () use ($platform, $claims, $journal): void {
            if ($platform->getDriverName() === 'pgsql') {
                $platform->select("SELECT pg_advisory_xact_lock(hashtext('libreria.aplicar_pago'), hashtext(?))", [$journal->codigo_generacion]);
            }
            $balances = $platform->table('saldo_pagos')->whereIn('id_saldo', $claims->pluck('saldo_id')->all())->orderBy('id_saldo')->lockForUpdate()->get()->keyBy('id_saldo');
            foreach ($claims as $claim) {
                if ($claim->ambiente !== '01') {
                    throw new RuntimeException('No se puede revertir una cuota de otro ambiente.');
                }
                $query = $platform->table('detalle_pagos')->where('uid_dte', $journal->codigo_generacion)
                    ->where('student_id', $claim->carnet_estudiante)->where('syear', $claim->anio)->where('mes_pagado', $claim->mes);
                foreach (['pago_beca' => $claim->financiamiento === 'beca', 'pago_bienhechor' => $claim->financiamiento === 'bienhechor'] as $column => $flag) {
                    $query->where(function ($query) use ($column, $flag): void {
                        $query->where($column, $flag);
                        if (! $flag) {
                            $query->orWhereNull($column);
                        }
                    });
                }
                $details = $query->lockForUpdate()->get();
                if ($details->isEmpty() && $journal->pagos_aplicados_at === null) {
                    continue;
                }
                $amount = BigDecimal::of((string) $claim->monto)->toScale(2, RoundingMode::HalfUp);
                $detail = $details->first();
                if ($details->count() !== 1 || $detail->numero_dte !== $journal->numero_control || ! $detail->facturado
                    || ! BigDecimal::of((string) $detail->monto)->toScale(2, RoundingMode::HalfUp)->isEqualTo($amount)) {
                    throw new RuntimeException('El pago original no tiene una referencia única y coincidente para revertirlo.');
                }
                if ($detail->estado === 'Anulado') {
                    continue;
                }
                [$paidColumn, $balanceColumn] = match ($claim->financiamiento) {
                    'normal' => ['pago', 'saldo'], 'beca' => ['pago_beca', 'saldo_beca'], 'bienhechor' => ['pago_bienhechor', 'saldo_bienhechor'],
                    default => throw new RuntimeException('Financiamiento desconocido.'),
                };
                $balance = $balances->get($claim->saldo_id);
                if ($balance === null || (string) $balance->student_id !== (string) $claim->carnet_estudiante || (int) $balance->syear !== (int) $claim->anio || (int) $balance->mes !== (int) $claim->mes) {
                    throw new RuntimeException('La cuota original ya no coincide con la plataforma.');
                }
                $paid = BigDecimal::of((string) ($balance->{$paidColumn} ?? 0))->toScale(2, RoundingMode::HalfUp);
                if (! $amount->isGreaterThan(0) || $paid->isLessThan($amount)) {
                    throw new RuntimeException('El monto pagado cambió y no permite revertir este DTE. Requiere conciliación.');
                }
                $platform->table('saldo_pagos')->where('id_saldo', $claim->saldo_id)->update([
                    $paidColumn => (string) $paid->minus($amount),
                    $balanceColumn => (string) BigDecimal::of((string) ($balance->{$balanceColumn} ?? 0))->toScale(2, RoundingMode::HalfUp)->plus($amount),
                    'fecha_actualizado' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
                $platform->table('detalle_pagos')->where('id_detalle', $detail->id_detalle)->update(['estado' => 'Anulado', 'updated_at' => DB::raw('CURRENT_TIMESTAMP')]);
            }
        }, 3);
    }
}
