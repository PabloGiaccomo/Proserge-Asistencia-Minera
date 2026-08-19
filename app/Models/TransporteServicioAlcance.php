<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransporteServicioAlcance extends Model
{
    protected $table = 'transporte_servicio_alcances';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'transporte_servicio_id',
        'rq_mina_actividad_grupo_id',
        'rq_mina_actividad_id',
        'grupo_trabajo_id',
        'sait_snapshot',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(TransporteServicio::class, 'transporte_servicio_id');
    }

    public function grupoOperativo(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividadGrupo::class, 'rq_mina_actividad_grupo_id');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividad::class, 'rq_mina_actividad_id');
    }

    public function grupoManPower(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajo::class, 'grupo_trabajo_id');
    }
}
