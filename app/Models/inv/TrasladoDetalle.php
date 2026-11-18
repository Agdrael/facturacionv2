<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class TrasladoDetalle extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'traslado_det_id';

    protected $table = 'inv.inv_traslados_det';
}
