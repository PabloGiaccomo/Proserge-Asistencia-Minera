<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluacionResidente extends Model
{
    protected $table = 'evaluacion_residente';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'fecha',
        'periodo_mes',
        'destino_tipo',
        'destino_id',
        'indicadores_kpi',
        'indicadores_kpi_items',
        'costos_servicio',
        'costos_servicio_items',
        'eventos_seguridad',
        'eventos_seguridad_respuesta',
        'reportes_calidad',
        'reportes_calidad_respuesta',
        'liderazgo_gestion',
        'liderazgo_gestion_innovacion',
        'innovacion',
        'total',
        'residente_id',
        'evaluador_id',
        'comentarios',
        'estado',
        'created_by_usuario_id',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'periodo_mes' => 'date',
        'indicadores_kpi_items' => 'array',
        'costos_servicio_items' => 'array',
    ];

    public function residente(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'residente_id');
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'evaluador_id');
    }
}
