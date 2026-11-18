<?php

namespace App\Models\Contaduria;

use Illuminate\Database\Eloquent\Model;

class CatalogoRecibo extends Model
{
    protected $table = 'public.catalogo_recibos';

    public $timestamps = false;

    protected $fillable = ['nombre_recibo', 'cuenta_abono', 'precio'];
}
