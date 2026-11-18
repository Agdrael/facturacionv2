<?php

namespace App;

enum TipoCliente: string
{
    case NORMAL = 'normal';
    case SUJETO_E = 'sujeto excluido';
    case EMPRESA = 'empresa';
}
