<?php

namespace App\Models\Libreria;

use Database\Factories\Libreria\CashReconciliationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashReconciliation extends Model
{
    /** @use HasFactory<CashReconciliationFactory> */
    use HasFactory;

    protected $table = 'libreria.arqueos';

    protected $fillable = ['sesion_id', 'snapshot', 'estado', 'original_path', 'entregado_path', 'firmado_path', 'archivos', 'entregado_at', 'revisado_por', 'revisado_at', 'firmado_por', 'firmado_nombre', 'firmado_empleado', 'firmado_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'archivos' => 'array', 'entregado_at' => 'immutable_datetime', 'revisado_at' => 'immutable_datetime', 'firmado_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CashSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'sesion_id');
    }
}
