<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParadaHerramientaGrupo extends Model
{
    protected $table = 'parada_herramienta_grupos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'lista_id',
        'grupo_trabajo_id',
        'nombre',
        'orden',
        'observaciones',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function lista(): BelongsTo
    {
        return $this->belongsTo(ParadaHerramientaLista::class, 'lista_id');
    }

    public function grupoTrabajo(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajo::class, 'grupo_trabajo_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ParadaHerramientaItem::class, 'grupo_id')->orderBy('orden');
    }
}
