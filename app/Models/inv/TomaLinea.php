<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class TomaLinea extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'toma_linea_id';

    protected $table = 'inv.inv_toma_lineas';
}
