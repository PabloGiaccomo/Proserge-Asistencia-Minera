<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluacionDesempeno extends Model
{
    protected $table = 'evaluacion_desempeno';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'fecha',
        'hora',
        'mina_id',
        'grupo_trabajo_id',
        'semana_parada',
        'desempeno_trabajo',
        'orden_limpieza',
        'compromiso',
        'respuesta_emocional',
        'seguridad_trabajo',
        'total',
        'observaciones',
        'supervisor_id',
        'trabajador_id',
        'tuvo_incidencia',
        'descripcion_incidencia',
        'asistencia_detalle_id',
        'asistencia_encabezado_id',
        'destino_tipo',
        'destino_id',
        'evaluado_por_usuario_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'tuvo_incidencia' => 'boolean',
    ];

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'trabajador_id');
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'supervisor_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function asistenciaDetalle(): BelongsTo
    {
        return $this->belongsTo(AsistenciaDetalle::class, 'asistencia_detalle_id');
    }
}
