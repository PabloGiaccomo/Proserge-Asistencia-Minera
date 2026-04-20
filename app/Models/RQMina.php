<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQMina extends Model
{
    protected $table = 'rq_mina';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'mina_id',
        'area',
        'fecha_inicio',
        'fecha_fin',
        'observaciones',
        'estado',
        'created_by_usuario_id',
        'enviado_at',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'enviado_at' => 'datetime',
    ];

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(RQMinaDetalle::class, 'rq_mina_id');
    }

    public function rqProserge(): HasMany
    {
        return $this->hasMany(RQProserge::class, 'rq_mina_id');
    }

    public function gruposTrabajo(): HasMany
    {
        return $this->hasMany(GrupoTrabajo::class, 'rq_mina_id');
    }
}
