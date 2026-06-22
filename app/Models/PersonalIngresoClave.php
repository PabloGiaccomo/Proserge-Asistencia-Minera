<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalIngresoClave extends Model
{
    protected $table = 'personal_ingreso_claves';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'fecha',
        'clave_hash',
        'clave_encrypted',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];
}
