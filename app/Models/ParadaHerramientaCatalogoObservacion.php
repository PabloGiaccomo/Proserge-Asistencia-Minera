<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParadaHerramientaCatalogoObservacion extends Model
{
    protected $table = 'parada_herramienta_catalogo_observaciones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'catalogo_id',
        'observacion',
        'observacion_normalizada',
        'observacion_hash',
        'usos',
        'last_used_at',
    ];

    protected $casts = [
        'usos' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function catalogo(): BelongsTo
    {
        return $this->belongsTo(ParadaHerramientaCatalogo::class, 'catalogo_id');
    }
}
