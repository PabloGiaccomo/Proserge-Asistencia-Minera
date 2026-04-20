<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluacionResidente extends Model
{
    protected $table = 'evaluacion_residente';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'fecha',
        'destino_tipo',
        'destino_id',
        'indicadores_kpi',
        'costos_servicio',
        'eventos_seguridad',
        'reportes_calidad',
        'liderazgo_gestion',
        'innovacion',
        'total',
        'residente_id',
        'evaluador_id',
        'comentarios',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];
}
