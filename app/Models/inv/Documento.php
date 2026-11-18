<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class Documento extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'doc_id';

    protected $table = 'inv.inv_documentos';
}
