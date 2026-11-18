<?php

namespace App\Services\Libreria;

use App\Enum\OrigenVenta;
use App\Models\Contaduria\Cuenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @phpstan-type AcademicProduct array{id: int, code: string, name: string, origin: string, incomeAccount: string, priceType: string, taxType: string, unit: string} */
class TuitionProductService
{
    public static function normalize(string $value): string
    {
        return Str::lower(Str::ascii(Str::squish($value)));
    }

    /** @return array<string, array{product: ?AcademicProduct, error: ?string}> */
    public function forStudent(int $grade, string $specialty, string $funding): array
    {
        $rows = DB::table('libreria.servicios_academicos as servicio')
            ->leftJoin('public.productos as producto', 'producto.id_producto', '=', 'servicio.producto_id')
            ->where('servicio.grado_id', $grade)->where('servicio.especialidad', self::normalize($specialty))
            ->where('servicio.financiamiento', $funding)
            ->get(['servicio.concepto', 'producto.id_producto', 'producto.codigo', 'producto.descrip', 'producto.grupo',
                'producto.tipo_prod', 'producto.tipo_prec', 'producto.iva2', 'producto.c_ing', 'producto.unidad']);
        $accounts = Cuenta::query()->whereIn('cuenta', $rows->pluck('c_ing')->filter()->all())->get(['cuenta'])
            ->map(fn (Cuenta $account): string => (string) $account->getAttribute('cuenta'))->all();
        $result = [];
        foreach ($rows as $row) {
            $error = match (true) {
                $row->id_producto === null => 'El producto configurado ya no está disponible.',
                $row->grupo !== OrigenVenta::COLEGIATURA->value || $row->tipo_prod !== 'S' => 'El producto configurado debe ser un servicio de colegiatura.',
                $row->tipo_prec !== 'F' || $row->iva2 !== 'E' => 'El producto configurado debe ser exento y de precio fijo.',
                trim((string) $row->c_ing) === '' || ! in_array((string) $row->c_ing, $accounts, true) => 'El producto no tiene una cuenta de ingreso válida.',
                default => null,
            };
            $result[(string) $row->concepto] = [
                'error' => $error,
                'product' => $error === null ? [
                    'id' => (int) $row->id_producto, 'code' => (string) $row->codigo, 'name' => (string) $row->descrip,
                    'origin' => (string) $row->grupo, 'incomeAccount' => (string) $row->c_ing,
                    'priceType' => (string) $row->tipo_prec, 'taxType' => (string) $row->iva2, 'unit' => (string) $row->unidad,
                ] : null,
            ];
        }

        return $result;
    }
}
