<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromedioDesempeno extends Model
{
    protected $table = 'promedio_desempeno';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'trabajador_id',
        'cantidad_evaluaciones',
        'promedio_total',
        'ultima_evaluacion',
    ];
}
