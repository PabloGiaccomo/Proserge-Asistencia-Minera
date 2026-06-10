<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MinaRequisito extends Model
{
    protected $table = 'mina_requisitos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'mina_id',
        'examen_id',
        'nombre',
        'tipo',
        'descripcion',
        'obligatorio',
        'critico',
        'reprogramable',
        'vigencia_dias',
        'activo',
        'orden',
        'permite_no_aplica',
        'permite_convalidacion_mina',
        'fecha_inicio_convalidacion',
        'fecha_fin_convalidacion',
        'convalidar_desde_otras_minas',
        'minas_origen_convalidacion_json',
        'vigencia_dias_override',
        'observacion_mina',
    ];

    protected $casts = [
        'obligatorio' => 'boolean',
        'critico' => 'boolean',
        'reprogramable' => 'boolean',
        'vigencia_dias' => 'integer',
        'activo' => 'boolean',
        'orden' => 'integer',
        'permite_no_aplica' => 'boolean',
        'permite_convalidacion_mina' => 'boolean',
        'fecha_inicio_convalidacion' => 'date',
        'fecha_fin_convalidacion' => 'date',
        'convalidar_desde_otras_minas' => 'boolean',
        'minas_origen_convalidacion_json' => 'array',
        'vigencia_dias_override' => 'integer',
    ];

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenMinero::class, 'examen_id');
    }
}
