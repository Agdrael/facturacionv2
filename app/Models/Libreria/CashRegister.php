<?php

namespace App\Models\Libreria;

use Database\Factories\Libreria\CashRegisterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CashRegister extends Model
{
    /** @use HasFactory<CashRegisterFactory> */
    use HasFactory;

    protected $table = 'libreria.cajas';

    protected $fillable = ['codigo'];

    /** @return HasMany<CashSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class, 'caja_id');
    }

    /** @return HasOne<CashSession, $this> */
    public function latestSession(): HasOne
    {
        return $this->hasOne(CashSession::class, 'caja_id')
            ->whereIn('libreria.caja_sesiones.id', CashSession::query()->selectRaw('MAX(id)')->groupBy('caja_id'));
    }
}
