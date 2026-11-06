<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolCambioEvento extends Model
{
    protected $table = 'rol_cambio_eventos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'antes_json' => 'array',
        'despues_json' => 'array',
        'avisos_json' => 'array',
        'permisos_cambiados' => 'boolean',
        'intentos' => 'integer',
        'proximo_intento_at' => 'datetime',
    ];
}
