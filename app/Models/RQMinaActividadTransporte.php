<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RQMinaActividadTransporte extends Model
{
    protected $table = 'rq_mina_actividad_transportes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_id',
        'actividad_id',
        'alcance',
        'unidad_carga',
        'unidades_transporte',
        'indicaciones',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividadGrupo::class, 'grupo_id');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividad::class, 'actividad_id');
    }
}
