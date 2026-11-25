<?php

namespace App\Models\Libreria;

use Database\Factories\Libreria\CashSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashSession extends Model
{
    /** @use HasFactory<CashSessionFactory> */
    use HasFactory;

    protected $table = 'libreria.caja_sesiones';

    protected $fillable = ['caja_id', 'fecha_operativa', 'abierto_por', 'abierto_nombre', 'abierto_empleado', 'abierto_at', 'cerrado_por', 'cerrado_nombre', 'cerrado_empleado', 'cerrado_at', 'codigo_reapertura', 'codigo_usado_at', 'sesion_anterior_id', 'motivo_reapertura'];

    protected $hidden = ['codigo_reapertura'];

    /** @return array{fecha_operativa: 'immutable_date', abierto_at: 'immutable_datetime', cerrado_at: 'immutable_datetime', codigo_usado_at: 'immutable_datetime', codigo_reapertura: 'encrypted'} */
    protected function casts(): array
    {
        return ['fecha_operativa' => 'immutable_date', 'abierto_at' => 'immutable_datetime', 'cerrado_at' => 'immutable_datetime', 'codigo_usado_at' => 'immutable_datetime', 'codigo_reapertura' => 'encrypted'];
    }

    /** @return BelongsTo<CashRegister, $this> */
    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'caja_id');
    }

    /** @return HasOne<CashReconciliation, $this> */
    public function reconciliation(): HasOne
    {
        return $this->hasOne(CashReconciliation::class, 'sesion_id');
    }
}
