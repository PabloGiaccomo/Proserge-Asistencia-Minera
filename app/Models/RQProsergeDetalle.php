<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQProsergeDetalle extends Model
{
    public const POSICION_TITULAR = 'TITULAR';
    public const POSICION_SUPLENTE = 'SUPLENTE';

    public const TIPO_REGULAR = 'REGULAR';
    public const TIPO_ADICIONAL = 'ADICIONAL';

    public const ESTADO_ASIGNADO = 'ASIGNADO';
    public const ESTADO_RETIRADO = 'RETIRADO';
    public const ESTADO_REEMPLAZADO = 'REEMPLAZADO';
    public const ESTADO_CANCELADO = 'CANCELADO';

    public const ESTADOS_ACTIVOS = [
        self::ESTADO_ASIGNADO,
    ];

    public const ESTADOS_INACTIVOS = [
        self::ESTADO_RETIRADO,
        self::ESTADO_REEMPLAZADO,
        self::ESTADO_CANCELADO,
    ];

    protected $table = 'rq_proserge_detalle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_proserge_id',
        'rq_mina_detalle_id',
        'personal_id',
        'puesto_asignado',
        'puesto_asignado_snapshot',
        'fecha_inicio',
        'fecha_fin',
        'comentario',
        'ultimo_turno_referencia',
        'posicion_asignacion',
        'tipo_asignacion',
        'estado_habilitacion_snapshot',
        'disponibilidad_snapshot',
        'asignado_por_id',
        'asignado_at',
        'actualizado_por_id',
        'reemplaza_a_id',
        'retirado_por_id',
        'retirado_at',
        'motivo_retiro',
        'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'disponibilidad_snapshot' => 'array',
        'asignado_at' => 'datetime',
        'retirado_at' => 'datetime',
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

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'asignado_por_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actualizado_por_id');
    }

    public function retiradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'retirado_por_id');
    }

    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_a_id');
    }

    public function reemplazos(): HasMany
    {
        return $this->hasMany(self::class, 'reemplaza_a_id');
    }

    public function distribucionesManPower(): HasMany
    {
        return $this->hasMany(GrupoTrabajoDetalle::class, 'rq_proserge_detalle_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(AsistenciaDetalle::class, 'rq_proserge_detalle_id');
    }

    public function isTitularRegular(): bool
    {
        return $this->posicion_asignacion === self::POSICION_TITULAR
            && $this->tipo_asignacion === self::TIPO_REGULAR;
    }

    public function isSuplenteRegular(): bool
    {
        return $this->posicion_asignacion === self::POSICION_SUPLENTE
            && $this->tipo_asignacion === self::TIPO_REGULAR;
    }

    public function isAdicional(): bool
    {
        return $this->tipo_asignacion === self::TIPO_ADICIONAL;
    }

    public function isSinClasificar(): bool
    {
        return !$this->posicion_asignacion || !$this->tipo_asignacion;
    }

    public function isActiva(): bool
    {
        return in_array((string) $this->estado, self::ESTADOS_ACTIVOS, true);
    }

    public function isRetirada(): bool
    {
        return (string) $this->estado === self::ESTADO_RETIRADO;
    }

    public function isReemplazada(): bool
    {
        return (string) $this->estado === self::ESTADO_REEMPLAZADO;
    }

    public function cuentaComoTitular(): bool
    {
        return $this->isActiva() && $this->isTitularRegular();
    }

    public function cuentaComoRespaldo(): bool
    {
        return $this->isActiva() && ($this->isSuplenteRegular() || $this->isAdicional());
    }
}
