<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQMinaActividadTransporte extends Model
{
    public const ORIGEN_EMPRESA = 'EMPRESA';
    public const ORIGEN_ALQUILADO = 'ALQUILADO';
    public const ORIGEN_OTRO = 'OTRO';

    public const ESTADO_REQUERIDO = 'REQUERIDO';
    public const ESTADO_ASIGNADO = 'ASIGNADO';
    public const ESTADO_EN_USO = 'EN_USO';
    public const ESTADO_RETIRADO = 'RETIRADO';
    public const ESTADO_REEMPLAZADO = 'REEMPLAZADO';
    public const ESTADO_DEVUELTO = 'DEVUELTO';
    public const ESTADO_INCIDENCIA = 'INCIDENCIA';

    public const RECEPCION_PENDIENTE = 'PENDIENTE';
    public const RECEPCION_RECIBIDO = 'RECIBIDO';
    public const RECEPCION_INCOMPLETO = 'INCOMPLETO';
    public const RECEPCION_NO_LLEGO = 'NO_LLEGO';
    public const RECEPCION_CON_OBSERVACION = 'CON_OBSERVACION';

    protected $table = 'rq_mina_actividad_transportes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_id',
        'actividad_id',
        'alcance',
        'unidad_carga',
        'origen',
        'unidades_transporte',
        'placas_asignadas',
        'fecha_inicio',
        'fecha_fin',
        'dias_uso',
        'estado_logistico',
        'indicaciones',
        'comentario_cambio',
        'incidencia_operativa',
        'recepcion_fecha',
        'recepcion_estado',
        'recepcion_observacion',
        'capacidad_camion',
        'doc_vehiculo_path',
        'doc_proserge_path',
        'doc_mantenimiento_path',
        'doc_checklist_path',
        'documentos',
        'orden',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'recepcion_fecha' => 'date',
        'dias_uso' => 'integer',
        'documentos' => 'array',
        'orden' => 'integer',
        'capacidad_camion' => 'string',
    ];

    public static function origenes(): array
    {
        return [
            self::ORIGEN_EMPRESA,
            self::ORIGEN_ALQUILADO,
            self::ORIGEN_OTRO,
        ];
    }

    public static function estadosLogisticos(): array
    {
        return [
            self::ESTADO_REQUERIDO,
            self::ESTADO_ASIGNADO,
            self::ESTADO_EN_USO,
            self::ESTADO_RETIRADO,
            self::ESTADO_REEMPLAZADO,
            self::ESTADO_DEVUELTO,
            self::ESTADO_INCIDENCIA,
        ];
    }

    public static function estadosRecepcion(): array
    {
        return [
            self::RECEPCION_PENDIENTE,
            self::RECEPCION_RECIBIDO,
            self::RECEPCION_INCOMPLETO,
            self::RECEPCION_NO_LLEGO,
            self::RECEPCION_CON_OBSERVACION,
        ];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividadGrupo::class, 'grupo_id');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividad::class, 'actividad_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(RQMinaActividadTransporteEvento::class, 'transporte_id');
    }
}
