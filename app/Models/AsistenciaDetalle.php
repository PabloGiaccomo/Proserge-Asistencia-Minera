<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsistenciaDetalle extends Model
{
    protected $table = 'asistencia_detalle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'asistencia_id',
        'trabajador_id',
        'hora_marcado',
        'estado',
        'observaciones',
    ];

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEncabezado::class, 'asistencia_id');
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'trabajador_id');
    }
}
