<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrupoTrabajoDetalle extends Model
{
    protected $table = 'grupo_trabajo_detalle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_trabajo_id',
        'personal_id',
        'hora_marcado',
        'estado_asistencia',
        'observaciones',
    ];

    public function grupoTrabajo(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajo::class, 'grupo_trabajo_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }
}
