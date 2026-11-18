<?php

namespace App\Models\Contaduria;

use App\Models\Producto as BaseProducto;

class Producto extends BaseProducto
{
    public const CREATED_AT = null;

    public const UPDATED_AT = 'fecha_modificacion';

    public $timestamps = true;

    protected $fillable = [
        'codigo', 'descrip', 'abreviado', 'grupo', 'ubicacion', 'ubica2', 'cod_prov', 'tipo_prod',
        'tipo_prec', 'unidad', 'iva2', 'p_costo', 'p_venta', 'cos_consig', 'ex_minima', 'ex_maxima',
        'c_inv', 'c_gas', 'c_ing', 'libreria', 'descuento', 'yellow_w',
        'yellow_w_descuento', 'procedencia', 'linea',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['descuento' => 'boolean', 'yellow_w' => 'boolean'];
    }
}
