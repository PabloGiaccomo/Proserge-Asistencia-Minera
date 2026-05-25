<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParadaHerramientaLista extends Model
{
    protected $table = 'parada_herramienta_listas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'anio_iso',
        'semana_iso',
        'fecha_limite_envio',
        'estado',
        'observaciones',
        'enviado_at',
        'created_by_usuario_id',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'anio_iso' => 'integer',
        'semana_iso' => 'integer',
        'fecha_limite_envio' => 'date',
        'enviado_at' => 'datetime',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(ParadaHerramientaGrupo::class, 'lista_id')->orderBy('orden');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }
}
