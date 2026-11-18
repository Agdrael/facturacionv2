<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class MigracionDocumentoDetalleOmitido extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $table = 'inv.inv_migracion_docs_det_omitidos';
}
