<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class TipoMovimiento extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'tipo_mov';

    protected $keyType = 'string';

    protected $table = 'inv.inv_cat_tipo_mov';
}
