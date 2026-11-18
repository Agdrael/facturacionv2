<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'public.productos';

    protected $primaryKey = 'id_producto';

    public $timestamps = false;

    protected $fillable = [
        'codigo',
        'descrip',
        'tipo_prod',
        'tipo_precio',
        'unidad',
        'iva2',
        'p_costo',
        'p_venta',
        'ex_minima',
        'ex_maxima',
        'c_inv',
        'c_gas',
        'c_ing',
        'descuento',
        'yellow_w',
        'yellow_w_descuento',
    ];
}
