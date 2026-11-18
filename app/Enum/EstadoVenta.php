<?php

namespace App\Enum;

enum EstadoVenta: string
{
    case GENERADO = 'GENERADO';
    case FACTURADO = 'FACTURADO';
    case ANULADO = 'ANULADO';
}
