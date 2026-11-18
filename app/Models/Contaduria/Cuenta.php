<?php

namespace App\Models\Contaduria;

use Illuminate\Database\Eloquent\Model;

class Cuenta extends Model
{
    protected $table = 'public.catalogo_contabilidad';

    protected $primaryKey = 'id_catalogo';

    public $timestamps = false;

    protected $fillable = ['cuenta', 'nivel', 'terminal', 'tipo_saldo', 'descripcion', 'cuenta2'];
}
