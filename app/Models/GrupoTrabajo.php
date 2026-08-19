<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GrupoTrabajo extends Model
{
    public const ESTADO_BORRADOR = 'BORRADOR';
    public const ESTADO_PROGRAMADO = 'PROGRAMADO';
    public const ESTADO_CERRADO = 'CERRADO';
    public const ESTADO_CANCELADO = 'CANCELADO';

    protected $table = 'grupo_trabajo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'fecha',
        'supervisor_id',
        'mina',
        'rq_mina_id',
        'rq_mina_plan_id',
        'rq_mina_actividad_grupo_id',
        'codigo_grupo',
        'nombre_snapshot',
        'area_snapshot',
        'sector_snapshot',
        'modulo_snapshot',
        'sait_snapshot',
        'supervisor_operativo_snapshot',
        'supervisor_seguridad_snapshot',
        'cantidad_planificada_snapshot',
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
        'observacion_planificacion',
        'justificacion_brecha',
        'created_by_id',
        'updated_by_id',
    ];

    protected $casts = [
        'fecha' => 'date',
        'cantidad_planificada_snapshot' => 'integer',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function rqProserge(): BelongsTo
    {
        return $this->belongsTo(RQProserge::class, 'rq_proserge_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RQMinaPlan::class, 'rq_mina_plan_id');
    }

    public function grupoOperativo(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividadGrupo::class, 'rq_mina_actividad_grupo_id');
    }

    public function actividades(): BelongsToMany
    {
        return $this->belongsToMany(
            RQMinaActividad::class,
            'grupo_trabajo_actividades',
            'grupo_trabajo_id',
            'rq_mina_actividad_id'
        )->withPivot(['cantidad_planificada_snapshot'])->withTimestamps();
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

    public function serviciosTransporte(): BelongsToMany
    {
        return $this->belongsToMany(
            TransporteServicio::class,
            'transporte_servicio_alcances',
            'grupo_trabajo_id',
            'transporte_servicio_id'
        )->whereNotNull('transporte_servicio_alcances.grupo_trabajo_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_id');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by_id');
    }

    public function isLegacy(): bool
    {
        return !$this->rq_mina_plan_id || !$this->rq_mina_actividad_grupo_id;
    }
}
