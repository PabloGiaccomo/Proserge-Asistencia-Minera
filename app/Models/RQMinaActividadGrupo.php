<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQMinaActividadGrupo extends Model
{
    protected $table = 'rq_mina_actividad_grupos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'area_operativa',
        'modulo',
        'nombre',
        'observaciones',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(RQMinaActividad::class, 'grupo_id')->orderBy('orden');
    }

    public function transportes(): HasMany
    {
        return $this->hasMany(RQMinaActividadTransporte::class, 'grupo_id')->orderBy('orden');
    }
}
