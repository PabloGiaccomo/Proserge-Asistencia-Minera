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
        'rq_mina_plan_id',
        'alcance',
        'unidad_carga',
        'fecha',
        'turno',
        'tipo_transporte',
        'capacidad_requerida',
        'cantidad_unidades_requeridas',
        'origen',
        'origen_snapshot',
        'destino_snapshot',
        'unidades_transporte',
        'placas_asignadas',
        'fecha_inicio',
        'fecha_fin',
        'dias_uso',
        'estado_logistico',
        'indicaciones',
        'observaciones',
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
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fecha' => 'date',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'recepcion_fecha' => 'date',
        'dias_uso' => 'integer',
        'capacidad_requerida' => 'integer',
        'cantidad_unidades_requeridas' => 'integer',
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

    /**
     * Etiquetas operativas de los estados logisticos, origen y recepcion.
     * Se centralizan aqui para que RQ Mina, Logistica y Transporte muestren
     * siempre el mismo texto al usuario.
     */
    public static function estadosLogisticosLabels(): array
    {
        return [
            self::ESTADO_REQUERIDO => 'Requerido',
            self::ESTADO_ASIGNADO => 'Asignado',
            self::ESTADO_EN_USO => 'En uso',
            self::ESTADO_RETIRADO => 'Retirado',
            self::ESTADO_REEMPLAZADO => 'Reemplazado',
            self::ESTADO_DEVUELTO => 'Devuelto',
            self::ESTADO_INCIDENCIA => 'Incidencia',
        ];
    }

    public static function origenesLabels(): array
    {
        return [
            self::ORIGEN_EMPRESA => 'Empresa',
            self::ORIGEN_ALQUILADO => 'Alquilado',
            self::ORIGEN_OTRO => 'Otro',
        ];
    }

    public static function estadosRecepcionLabels(): array
    {
        return [
            self::RECEPCION_PENDIENTE => 'Pendiente',
            self::RECEPCION_RECIBIDO => 'Recibido',
            self::RECEPCION_INCOMPLETO => 'Incompleto',
            self::RECEPCION_NO_LLEGO => 'No llego',
            self::RECEPCION_CON_OBSERVACION => 'Con observacion',
        ];
    }

    public static function estadoLogisticoLabel(?string $estado): string
    {
        $estado = (string) $estado;

        return self::estadosLogisticosLabels()[$estado] ?? 'Requerido';
    }

    public static function origenLabel(?string $origen): string
    {
        $origen = (string) $origen;

        return self::origenesLabels()[$origen] ?? 'Sin definir';
    }

    public static function recepcionEstadoLabel(?string $estado): string
    {
        $estado = (string) $estado;

        return self::estadosRecepcionLabels()[$estado] ?? 'Pendiente';
    }

    public static function estadoLogisticoTone(?string $estado): string
    {
        return match ((string) $estado) {
            self::ESTADO_INCIDENCIA => 'danger',
            self::ESTADO_REQUERIDO => 'warning',
            self::ESTADO_RETIRADO, self::ESTADO_DEVUELTO, self::ESTADO_REEMPLAZADO => 'muted',
            default => 'ok',
        };
    }

    public const ATENCION_REQUERIDO = 'REQUERIDO';
    public const ATENCION_CON_DATOS = 'CON_DATOS';
    public const ATENCION_ATENDIDO = 'ATENDIDO';

    /**
     * Atendido es un estado explicito: solo cuando Logistica movio el estado
     * logistico. Cargar placa u origen no equivale a haber confirmado el estado.
     */
    public function estaAtendido(): bool
    {
        return (string) ($this->estado_logistico ?: self::ESTADO_REQUERIDO) !== self::ESTADO_REQUERIDO;
    }

    /**
     * Hay datos logisticos cargados aunque el estado siga en REQUERIDO.
     * Se usa para avisar que falta confirmar el estado, no para darlo por atendido.
     */
    public function tieneDatosLogisticos(): bool
    {
        if (trim((string) $this->origen) !== '' || trim((string) $this->placas_asignadas) !== '') {
            return true;
        }

        if (trim((string) $this->capacidad_camion) !== '') {
            return true;
        }

        foreach (['doc_vehiculo_path', 'doc_proserge_path', 'doc_mantenimiento_path', 'doc_checklist_path'] as $column) {
            if (trim((string) ($this->{$column} ?? '')) !== '') {
                return true;
            }
        }

        if (is_array($this->documentos) && $this->documentos !== []) {
            return true;
        }

        return (string) ($this->recepcion_estado ?: self::RECEPCION_PENDIENTE) !== self::RECEPCION_PENDIENTE;
    }

    public function estadoAtencion(): string
    {
        if ($this->estaAtendido()) {
            return self::ATENCION_ATENDIDO;
        }

        return $this->tieneDatosLogisticos() ? self::ATENCION_CON_DATOS : self::ATENCION_REQUERIDO;
    }

    public static function estadoAtencionLabel(string $estado): string
    {
        return match ($estado) {
            self::ATENCION_ATENDIDO => 'Atendido',
            self::ATENCION_CON_DATOS => 'Con datos sin confirmar',
            default => 'Requerido',
        };
    }

    public static function estadoAtencionTone(string $estado): string
    {
        return match ($estado) {
            self::ATENCION_ATENDIDO => 'ok',
            self::ATENCION_CON_DATOS => 'info',
            default => 'warning',
        };
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividadGrupo::class, 'grupo_id');
    }

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(RQMinaActividad::class, 'actividad_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RQMinaPlan::class, 'rq_mina_plan_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(RQMinaActividadTransporteEvento::class, 'transporte_id');
    }
}
