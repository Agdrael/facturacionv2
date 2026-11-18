<?php

namespace App\Models\Dte;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use stdClass;
use UnexpectedValueException;

abstract class DteSantaCeciliaModel extends Model
{
    protected $connection = 'dtesantacecilia';

    public $timestamps = false;

    /**
     * @param  Builder<static>  $query
     * @return Collection<int, array{codigo: string, valor: string}>
     */
    protected static function optionsFromQuery(Builder $query, string $codigo, string $descripcion): Collection
    {
        $opciones = $query
            ->select([$codigo.' as codigo', $descripcion.' as valor'])
            ->distinct()
            ->toBase()
            ->get()
            ->map(fn (stdClass $opcion): array => [
                'codigo' => (string) $opcion->codigo,
                'valor' => (string) $opcion->valor,
            ])
            ->values();

        $duplicados = $opciones->duplicatesStrict('codigo')->unique();

        if ($duplicados->isNotEmpty()) {
            throw new UnexpectedValueException('El catálogo '.static::class.' contiene códigos con descripciones diferentes: '.$duplicados->implode(', ').'.');
        }

        return $opciones;
    }
}
