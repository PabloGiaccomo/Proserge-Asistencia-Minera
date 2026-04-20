<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GrupoTrabajo extends Model
{
    protected $table = 'grupo_trabajo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'fecha',
        'supervisor_id',
        'mina',
        'rq_mina_id',
        'rq_proserge_id',
        'servicio',
        'area',
        'paradero',
        'paradero_link',
        'unidad',
        'destino_tipo',
        'destino_id',
        'horario_salida',
        'turno',
        'estado',
        'observaciones',
        'created_by_id',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function rqProserge(): BelongsTo
    {
        return $this->belongsTo(RQProserge::class, 'rq_proserge_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'supervisor_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(GrupoTrabajoDetalle::class, 'grupo_trabajo_id');
    }

    public function asistencia(): HasOne
    {
        return $this->hasOne(AsistenciaEncabezado::class, 'grupo_trabajo_id');
    }
}
