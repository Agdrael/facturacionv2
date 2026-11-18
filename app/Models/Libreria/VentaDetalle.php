<?php

namespace App\Models\Libreria;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaDetalle extends Model
{
    protected $table = 'libreria.venta_detalles';

    protected $fillable = [
        'venta_id',
        'producto_id',
        'carnet_estudiante',
        'nombre',
        'cantidad',
        'valor_unitario',
        'valor_unitario_iva',
        'porcentaje_descuento',
        'valor_descuento',
        'valor_final',
        'cuenta_cargo',
        'cuenta_abono',
        'origen',
    ];

    protected $casts = [
        'venta_id' => 'integer',
        'producto_id' => 'integer',
        'cantidad' => 'decimal:2',
        'valor_unitario' => 'decimal:8',
        'valor_unitario_iva' => 'decimal:8',
        'porcentaje_descuento' => 'decimal:2',
        'valor_descuento' => 'decimal:8',
        'valor_final' => 'decimal:8',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_id', 'id_producto');
    }
}
