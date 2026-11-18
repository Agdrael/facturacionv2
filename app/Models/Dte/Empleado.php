<?php

namespace App\Models\Dte;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $connection = 'dtesantacecilia';

    protected $table = 'public.empleados';

    protected $primaryKey = 'empleado_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;
}
