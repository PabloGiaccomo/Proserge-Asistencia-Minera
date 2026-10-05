<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamenMineroCatalogoCambio extends Model
{
    protected $table = 'examen_minero_catalogo_cambios';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['antes_json' => 'array', 'despues_json' => 'array'];
}
