<?php

namespace App\Models\Libreria;

use App\TamanoEmpresa;
use App\TipoCliente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'libreria.clientes';

    protected $fillable = [
        'nombre',
        'direccion',
        'telefono_1',
        'telefono_2',
        'email',
        'registro_fiscal',
        'nit',
        'dui',
        'nacionalidad',
        'departamento',
        'municipio',
        'distrito',
        'domiciliado',
        'credito',
        'tamano',
        'tipo_cliente',
        'actividad_economica',
        'cuenta_contable',
    ];

    protected $casts = [
        'domiciliado' => 'boolean',
        'credito' => 'boolean',
        'tipo_cliente' => TipoCliente::class,
        'tamano' => TamanoEmpresa::class,
    ];

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }
}
