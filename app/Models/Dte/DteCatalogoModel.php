<?php

namespace App\Models\Dte;

use Illuminate\Support\Collection;

abstract class DteCatalogoModel extends DteSantaCeciliaModel
{
    protected $primaryKey = 'codigo';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @return Collection<int, array{codigo: string, valor: string}> */
    public static function catalogOptions(): Collection
    {
        return static::optionsFromQuery(
            static::query()->orderBy('codigo')->orderBy('valores'),
            'codigo',
            'valores',
        );
    }
}
