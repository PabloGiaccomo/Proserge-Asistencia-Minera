<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQProserge extends Model
{
    protected $table = 'rq_proserge';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'mina_id',
        'responsable_rrhh_id',
        'estado',
        'comentario_planner',
        'comentario_rrhh',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function responsableRrhh(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'responsable_rrhh_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(RQProsergeDetalle::class, 'rq_proserge_id');
    }

    public function gruposTrabajo(): HasMany
    {
        return $this->hasMany(GrupoTrabajo::class, 'rq_proserge_id');
    }
}
