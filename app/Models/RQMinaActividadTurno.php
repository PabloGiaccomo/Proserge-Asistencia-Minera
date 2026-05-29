<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RQMinaActividadTurno extends Model
{
    protected $table = 'rq_mina_actividad_turnos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'actividad_id',
        'fecha',
        'dia_label',
        'turno_a',
        'turno_b',
        'real',
        'orden',
    ];

    protected $casts = [
        'fecha' => 'date',
        'orden' => 'integer',
    ];

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividad::class, 'actividad_id');
    }
}
