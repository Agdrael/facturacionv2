<?php

namespace App\Models\Dte;

use Illuminate\Support\Collection;

class Municipio extends DteSantaCeciliaModel
{
    protected $table = 'public.municipios';

    protected $primaryKey = 'id_muni';

    /** @return Collection<int, array{codigo: string, valor: string}> */
    public static function catalogOptions(string $departamento): Collection
    {
        return static::optionsFromQuery(
            static::query()
                ->where('departamento_codigo', $departamento)
                ->orderBy('nombre')
                ->orderBy('codigo'),
            'codigo',
            'nombre',
        );
    }
}
