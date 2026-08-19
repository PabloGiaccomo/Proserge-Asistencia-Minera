<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsistenciaDetalle extends Model
{
    public const ESTADO_PRESENTE = 'PRESENTE';
    public const ESTADO_AUSENTE = 'AUSENTE';
    public const ESTADO_TARDANZA = 'TARDANZA';
    public const ESTADO_JUSTIFICADO = 'JUSTIFICADO';
    public const ESTADO_NO_CORRESPONDE = 'NO_CORRESPONDE';

    public const ESTADOS_VALIDOS = [
        self::ESTADO_PRESENTE,
        self::ESTADO_AUSENTE,
        self::ESTADO_TARDANZA,
        self::ESTADO_JUSTIFICADO,
        self::ESTADO_NO_CORRESPONDE,
    ];

    public const ESTADOS_REAL = [
        self::ESTADO_PRESENTE,
        self::ESTADO_TARDANZA,
    ];

    protected $table = 'asistencia_detalle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'asistencia_id',
        'grupo_trabajo_detalle_id',
        'rq_proserge_detalle_id',
        'trabajador_id',
        'puesto_snapshot',
        'posicion_asignacion_snapshot',
        'tipo_asignacion_snapshot',
        'estado_distribucion_snapshot',
        'hora_marcado',
        'estado',
        'motivo_estado',
        'origen_registro',
        'observaciones',
        'marcado_por_id',
        'marcado_at',
        'updated_by',
    ];

    protected $casts = [
        'marcado_at' => 'datetime',
    ];

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(AsistenciaEncabezado::class, 'asistencia_id');
    }

    public function grupoTrabajoDetalle(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajoDetalle::class, 'grupo_trabajo_detalle_id');
    }

    public function rqProsergeDetalle(): BelongsTo
    {
        return $this->belongsTo(RQProsergeDetalle::class, 'rq_proserge_detalle_id');
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'trabajador_id');
    }

    public function marcador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'marcado_por_id');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by');
    }

    public function isLegacy(): bool
    {
        return !$this->grupo_trabajo_detalle_id;
    }

    public function cuentaComoReal(): bool
    {
        return in_array((string) $this->estado, self::ESTADOS_REAL, true);
    }
}
