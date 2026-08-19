<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrupoTrabajoDetalleActividad extends Model
{
    protected $table = 'grupo_trabajo_detalle_actividades';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_trabajo_detalle_id',
        'rq_mina_actividad_id',
        'es_principal',
        'observacion',
    ];

    protected $casts = [
        'es_principal' => 'boolean',
    ];

    public function detalle(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajoDetalle::class, 'grupo_trabajo_detalle_id');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividad::class, 'rq_mina_actividad_id');
    }
}
