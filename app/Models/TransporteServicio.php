<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransporteServicio extends Model
{
    public const TIPO_PERSONAL = 'PERSONAL';
    public const TIPO_CARGA = 'CARGA';

    public const TRAMO_IDA = 'IDA';
    public const TRAMO_RETORNO = 'RETORNO';
    public const TRAMO_TRASLADO_INTERNO = 'TRASLADO_INTERNO';

    public const ESTADO_BORRADOR = 'BORRADOR';
    public const ESTADO_ASIGNADO = 'ASIGNADO';
    public const ESTADO_CONFIRMADO = 'CONFIRMADO';
    public const ESTADO_EN_SERVICIO = 'EN_SERVICIO';
    public const ESTADO_FINALIZADO = 'FINALIZADO';
    public const ESTADO_CANCELADO = 'CANCELADO';

    protected $table = 'transporte_servicios';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'rq_mina_plan_id',
        'tipo',
        'fecha',
        'turno',
        'tramo',
        'transportista',
        'tipo_vehiculo',
        'placa',
        'conductor_personal_id',
        'conductor_nombre_snapshot',
        'capacidad',
        'hora_salida',
        'hora_retorno',
        'origen',
        'destino',
        'estado',
        'observaciones',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'capacidad' => 'integer',
    ];

    public static function tipos(): array
    {
        return [self::TIPO_PERSONAL, self::TIPO_CARGA];
    }

    public static function tramos(): array
    {
        return [self::TRAMO_IDA, self::TRAMO_RETORNO, self::TRAMO_TRASLADO_INTERNO];
    }

    public static function estados(): array
    {
        return [
            self::ESTADO_BORRADOR,
            self::ESTADO_ASIGNADO,
            self::ESTADO_CONFIRMADO,
            self::ESTADO_EN_SERVICIO,
            self::ESTADO_FINALIZADO,
            self::ESTADO_CANCELADO,
        ];
    }

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RQMinaPlan::class, 'rq_mina_plan_id');
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'conductor_personal_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by');
    }

    public function actualizador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by');
    }

    public function alcances(): HasMany
    {
        return $this->hasMany(TransporteServicioAlcance::class, 'transporte_servicio_id')->orderBy('orden');
    }

    public function pasajeros(): HasMany
    {
        return $this->hasMany(TransporteServicioPasajero::class, 'transporte_servicio_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(TransporteServicioEvento::class, 'transporte_servicio_id');
    }

    public function gruposOperativos(): BelongsToMany
    {
        return $this->belongsToMany(
            RQMinaActividadGrupo::class,
            'transporte_servicio_alcances',
            'transporte_servicio_id',
            'rq_mina_actividad_grupo_id'
        )->whereNotNull('transporte_servicio_alcances.rq_mina_actividad_grupo_id');
    }

    public function actividades(): BelongsToMany
    {
        return $this->belongsToMany(
            RQMinaActividad::class,
            'transporte_servicio_alcances',
            'transporte_servicio_id',
            'rq_mina_actividad_id'
        )->whereNotNull('transporte_servicio_alcances.rq_mina_actividad_id');
    }

    public function gruposManPower(): BelongsToMany
    {
        return $this->belongsToMany(
            GrupoTrabajo::class,
            'transporte_servicio_alcances',
            'transporte_servicio_id',
            'grupo_trabajo_id'
        )->whereNotNull('transporte_servicio_alcances.grupo_trabajo_id');
    }
}
