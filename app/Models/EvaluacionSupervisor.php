<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'import_lote_id',
        'source_key',
        'source_file',
        'source_sheet',
        'source_row',
        'source_id',
        'source_evaluador',
        'source_mina',
        'calculo_version',
        'source_payload',
    ];

    protected $casts = [
        'respuestas' => 'array',
        'fecha' => 'date',
        'source_payload' => 'array',
    ];

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'evaluador_id');
    }

    public function usuarioEvaluador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }

    public function evaluado(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'evaluado_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }
}
