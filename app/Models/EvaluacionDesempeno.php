<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
