<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamenMineroAsociacion extends Model
{
    protected $table = 'examen_minero_asociaciones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
