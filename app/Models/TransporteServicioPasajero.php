<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransporteServicioPasajero extends Model
{
    public const ESTADO_ASIGNADO = 'ASIGNADO';
    public const ESTADO_RETIRADO = 'RETIRADO';
    public const ESTADO_NO_ABORDO = 'NO_ABORDO';
    public const ESTADO_REUBICADO = 'REUBICADO';

    public const ESTADOS_ACTIVOS = [self::ESTADO_ASIGNADO];

    protected $table = 'transporte_servicio_pasajeros';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'transporte_servicio_id',
        'grupo_trabajo_detalle_id',
        'personal_id',
        'tramo',
        'estado',
        'asignado_por_id',
        'asignado_at',
        'retirado_por_id',
        'retirado_at',
        'motivo_retiro',
    ];

    protected $casts = [
        'asignado_at' => 'datetime',
        'retirado_at' => 'datetime',
    ];

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(TransporteServicio::class, 'transporte_servicio_id');
    }

    public function grupoTrabajoDetalle(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajoDetalle::class, 'grupo_trabajo_detalle_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function asignador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asignado_por_id');
    }

    public function retirador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'retirado_por_id');
    }
}
