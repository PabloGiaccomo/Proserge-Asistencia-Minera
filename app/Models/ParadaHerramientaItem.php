<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParadaHerramientaItem extends Model
{
    protected $table = 'parada_herramienta_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_id',
        'tipo',
        'descripcion',
        'cantidad_solicitada',
        'observaciones',
        'pedido_solicitado_at',
        'pedido_llego_at',
        'orden',
    ];

    protected $casts = [
        'cantidad_solicitada' => 'integer',
        'pedido_solicitado_at' => 'date',
        'pedido_llego_at' => 'date',
        'orden' => 'integer',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(ParadaHerramientaGrupo::class, 'grupo_id');
    }
}
