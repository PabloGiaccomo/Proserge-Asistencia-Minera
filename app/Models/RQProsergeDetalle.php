<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RQProsergeDetalle extends Model
{
    protected $table = 'rq_proserge_detalle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_proserge_id',
        'rq_mina_detalle_id',
        'personal_id',
        'puesto_asignado',
        'fecha_inicio',
        'fecha_fin',
        'comentario',
        'ultimo_turno_referencia',
        'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function rqProserge(): BelongsTo
    {
        return $this->belongsTo(RQProserge::class, 'rq_proserge_id');
    }

    public function rqMinaDetalle(): BelongsTo
    {
        return $this->belongsTo(RQMinaDetalle::class, 'rq_mina_detalle_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }
}
