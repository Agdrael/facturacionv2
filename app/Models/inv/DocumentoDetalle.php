<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class DocumentoDetalle extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'doc_det_id';

    protected $table = 'inv.inv_documentos_det';
}
