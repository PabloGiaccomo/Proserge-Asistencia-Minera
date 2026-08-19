<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrupoTrabajoDetalle extends Model
{
    public const ESTADO_DISTRIBUCION_ASIGNADO = 'ASIGNADO';
    public const ESTADO_DISTRIBUCION_RETIRADO = 'RETIRADO';
    public const ESTADO_DISTRIBUCION_REUBICADO = 'REUBICADO';
    public const ESTADO_DISTRIBUCION_CANCELADO = 'CANCELADO';

    public const ESTADOS_DISTRIBUCION_ACTIVOS = [
        self::ESTADO_DISTRIBUCION_ASIGNADO,
    ];

    protected $table = 'grupo_trabajo_detalle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_trabajo_id',
        'personal_id',
        'rq_proserge_detalle_id',
        'puesto_asignado_snapshot',
        'posicion_asignacion_snapshot',
        'tipo_asignacion_snapshot',
        'estado_habilitacion_snapshot',
        'estado_distribucion',
        'asignado_por_id',
        'asignado_at',
        'retirado_por_id',
        'retirado_at',
        'motivo_retiro',
        'observacion',
        'hora_marcado',
        'estado_asistencia',
        'observaciones',
    ];

    protected $casts = [
        'asignado_at' => 'datetime',
        'retirado_at' => 'datetime',
    ];

    public function grupoTrabajo(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajo::class, 'grupo_trabajo_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function rqProsergeDetalle(): BelongsTo
    {
        return $this->belongsTo(RQProsergeDetalle::class, 'rq_proserge_detalle_id');
    }

    public function serviciosTransporte(): HasMany
    {
        return $this->hasMany(TransporteServicioPasajero::class, 'grupo_trabajo_detalle_id');
    }

    public function asistenciaDetalles(): HasMany
    {
        return $this->hasMany(AsistenciaDetalle::class, 'grupo_trabajo_detalle_id');
    }

    public function actividadesPrincipales(): HasMany
    {
        return $this->hasMany(GrupoTrabajoDetalleActividad::class, 'grupo_trabajo_detalle_id');
    }

    public function asignador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asignado_por_id');
    }

    public function retirador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'retirado_por_id');
    }

    public function isDistribucionActiva(): bool
    {
        return in_array((string) ($this->estado_distribucion ?? self::ESTADO_DISTRIBUCION_ASIGNADO), self::ESTADOS_DISTRIBUCION_ACTIVOS, true);
    }
}
