<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluacionSupervisor extends Model
{
    protected $table = 'evaluacion_supervisor';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'evaluador_id',
        'evaluado_id',
        'fecha',
        'mina_id',
        'grupo_trabajo_id',
        'asistencia_encabezado_id',
        'destino_tipo',
        'destino_id',
        'resultado_final',
        'comentarios_finales',
        'aspectos_positivos',
        'capacitaciones_recomendadas',
        'firma_supervisor',
        'respuestas',
        'estado',
        'created_by_usuario_id',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'respuestas' => 'array',
        'fecha' => 'date',
    ];
}
