<?php

namespace App\Models\Plataforma;

use Illuminate\Database\Eloquent\Model;

class Estudiante extends Model
{
    protected $connection = 'plataforma';

    protected $table = 'students';

    protected $primaryKey = 'student_id';

    protected $keyType = 'string';

    public $incrementing = false;
}
