<?php

namespace App\Models\Contaduria;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $table = 'public.proveedores';

    protected $primaryKey = 'id_proveedor';

    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';

    protected $fillable = [
        'nombre_proveedor', 'n_comercial', 'contacto', 'id_nacionalidad', 'domiciliado', 'direccion',
        'id_departamento', 'codigo_distrito', 'municipio', 'codigo_act_economica', 'telefono', 'telefono2', 'nit', 'dui',
        'r_fiscal', 'correo_electronico', 'giro', 'cuenta', 'tamaño',
    ];
}
