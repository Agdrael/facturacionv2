<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class TipoDocumento extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'doc_tipo';

    protected $keyType = 'string';

    protected $table = 'inv.inv_cat_doc_tipo';
}
