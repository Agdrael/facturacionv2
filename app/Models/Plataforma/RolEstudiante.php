<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Model;

class RolEstudiante extends Model
{
    protected $connection = 'plataforma';

    protected $table = 'student_enrollment';
}
