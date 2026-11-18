<?php

namespace App\Models\Dte;

use Illuminate\Support\Collection;

class Distrito extends DteSantaCeciliaModel
{
    protected $table = 'public.distritos';

    /** @return Collection<int, array{codigo: string, valor: string}> */
    public static function catalogOptions(string $departamento, string $municipio): Collection
    {
        if (trim($departamento) === '' || trim($municipio) === '') {
            return collect();
        }

        return static::optionsFromQuery(
            static::query()
                ->where('departamento_codigo', $departamento)
                ->where('municipio_codigo', $municipio)
                ->orderBy('nombre')
                ->orderBy('id'),
            'id',
            'nombre',
        );
    }
}
