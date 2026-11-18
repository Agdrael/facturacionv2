<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Model;

class GradoEstudiante extends Model
{
    protected $connection = 'plataforma';

    protected $table = 'school_gradelevels';
}
