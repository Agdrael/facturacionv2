<?php

namespace App\Models\Libreria;

use App\Enum\EstadoVenta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Venta extends Model
{
    protected $table = 'libreria.ventas';

    protected $fillable = [
        'cliente_id',
        'tipo_documento',
        'fecha',
        'caja',
        'uuidv4',
        'numero_control',
        'operacion',
        'pagos',
        'plazos',
        'total',
        'estado',
    ];

    protected $casts = [
        'cliente_id' => 'integer',
        'fecha' => 'date',
        'uuidv4' => 'string',
        'total' => 'decimal:8',
        'estado' => EstadoVenta::class,
        'tipo_documento' => 'string',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
