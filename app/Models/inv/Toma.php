<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class Toma extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'toma_id';

    protected $table = 'inv.inv_tomas';
}
