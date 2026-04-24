<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinaParadero extends Model
{
    protected $table = 'mina_paraderos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'mina_id',
        'nombre',
        'ubicacion',
        'link_ubicacion',
        'estado',
    ];

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }
}
