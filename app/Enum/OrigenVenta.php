<?php

namespace App\Enum;

enum OrigenVenta: string
{
    case LIBRERIA = 'LIBRERIA';
    case CFP = 'CFP';
    case RECEPCION = 'RECEPCION';
    case COLEGIATURA = 'COLEGIATURA';
    case REGISTRO = 'REGISTRO';
    case CXC = 'CXC';
}
