<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class Kardex extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'kardex_id';

    protected $table = 'inv.inv_kardex';
}
