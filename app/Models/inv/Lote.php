<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class Lote extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'lote_id';

    protected $table = 'inv.inv_lotes';
}
