<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Model;

class SaldoPago extends Model
{
    protected $connection = 'plataforma';

    protected $table = 'saldo_pagos';

    protected $primaryKey = 'id_saldo';

    public $timestamps = false;
}
