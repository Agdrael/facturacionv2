<?php

namespace App\Models\inv;

use Illuminate\Database\Eloquent\Model;

class Bodega extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'bodega_id';

    protected $table = 'inv.inv_bodegas';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'activo',
        'creado_at',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'creado_at' => 'datetime',
    ];
}
