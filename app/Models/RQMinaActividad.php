<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQMinaActividad extends Model
{
    protected $table = 'rq_mina_actividades';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_id',
        'sait',
        'sector',
        'area',
        'ait_trabajo',
        'detalle_trabajos_relevantes',
        'supervisor_campo_dia',
        'supervisor_campo_noche',
        'supervisor_seguridad_dia',
        'supervisor_seguridad_noche',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividadGrupo::class, 'grupo_id');
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(RQMinaActividadTurno::class, 'actividad_id')->orderBy('orden');
    }

    public function transportes(): HasMany
    {
        return $this->hasMany(RQMinaActividadTransporte::class, 'actividad_id')->orderBy('orden');
    }
}
