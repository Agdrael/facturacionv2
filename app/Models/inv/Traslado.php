<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class Traslado extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'traslado_id';

    protected $table = 'inv.inv_traslados';
}
