<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class Tercero extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'tercero_id';

    protected $table = 'inv.inv_terceros';
}
