<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParadaHerramientaItem extends Model
{
    public const TIPO_BASE = 'BASE';
    public const TIPO_ADICIONAL = 'ADICIONAL';

    public const CATEGORIA_HERRAMIENTA = 'HERRAMIENTA';
    public const CATEGORIA_CONSUMIBLE = 'CONSUMIBLE';

    protected $table = 'parada_herramienta_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_id',
        'tipo',
        'categoria',
        'descripcion',
        'cantidad_solicitada',
        'cantidad_entregada',
        'cantidad_recibida',
        'unidad',
        'observaciones',
        'pedido_solicitado_at',
        'pedido_llego_at',
        'orden',
    ];

    protected $casts = [
        'cantidad_solicitada' => 'integer',
        'cantidad_entregada' => 'integer',
        'cantidad_recibida' => 'integer',
        'pedido_solicitado_at' => 'date',
        'pedido_llego_at' => 'date',
        'orden' => 'integer',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(ParadaHerramientaGrupo::class, 'grupo_id');
    }
}
