<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mina extends Model
{
    protected $table = 'minas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'nombre',
        'unidad_minera',
        'ubicacion',
        'link_ubicacion',
        'color',
        'estado',
    ];
}
