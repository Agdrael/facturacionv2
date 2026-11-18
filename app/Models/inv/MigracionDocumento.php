<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class MigracionDocumento extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'mig_id';

    protected $table = 'inv.inv_migracion_docs';
}
