<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParadaEjecucionResumen extends Model
{
    protected $table = 'parada_ejecucion_resumen';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'rq_mina_plan_id',
        'rq_mina_actividad_grupo_id',
        'rq_mina_actividad_id',
        'actividad_key',
        'fecha',
        'turno',
        'planificado',
        'programado',
        'presentes',
        'tardanzas',
        'ausentes',
        'justificados',
        'no_corresponde',
        'pendientes_marcacion',
        'titulares_presentes',
        'suplentes_presentes',
        'adicionales_presentes',
        'sin_clasificar_presentes',
        'personal_sin_actividad',
        'brecha_plan_programado',
        'brecha_programado_real',
        'brecha_plan_real',
        'exceso_programado',
        'exceso_real',
        'porcentaje_programacion',
        'porcentaje_asistencia',
        'porcentaje_cumplimiento_real',
        'asistencia_cerrada',
        'datos_completos',
        'source_closed_at',
        'recalculated_at',
    ];

    protected $casts = [
        'fecha' => 'date',
        'source_closed_at' => 'datetime',
        'recalculated_at' => 'datetime',
        'asistencia_cerrada' => 'boolean',
        'datos_completos' => 'boolean',
        'porcentaje_programacion' => 'decimal:2',
        'porcentaje_asistencia' => 'decimal:2',
        'porcentaje_cumplimiento_real' => 'decimal:2',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RQMinaPlan::class, 'rq_mina_plan_id');
    }

    public function grupoOperativo(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividadGrupo::class, 'rq_mina_actividad_grupo_id');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividad::class, 'rq_mina_actividad_id');
    }
}
