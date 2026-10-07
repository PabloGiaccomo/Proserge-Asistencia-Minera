<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EppEntrega extends Model
{
    public const ESTADO_ENTREGADO = 'ENTREGADO';

    public const ESTADO_CAMBIADO = 'CAMBIADO';

    public const ESTADO_DEVUELTO = 'DEVUELTO';

    public const ESTADO_USO_INCORRECTO = 'USO_INCORRECTO';

    public const ESTADO_PERDIDA_OLVIDO = 'PERDIDA_OLVIDO';

    public const ESTADO_CONSUMIDO = 'CONSUMIDO';

    public const ESTADO_ANULADO = 'ANULADO';

    public const INTEGRIDAD_LEGACY = 'LEGACY_SIN_FIRMA';

    protected $table = 'epp_entregas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'epp_id',
        'cantidad',
        'talla',
        'color',
        'atributos_json',
        'fecha_entrega',
        'fecha_vencimiento_calendario',
        'vida_util_dias_snapshot',
        'estado',
        'motivo_cambio',
        'observacion',
        'devuelto_at',
        'registrado_por_usuario_id',
        'cerrado_por_usuario_id',
        'operacion_id',
        'operacion_detalle_id',
        'cantidad_original',
        'cantidad_pendiente_devolucion',
        'politica_devolucion_snapshot',
        'es_legacy',
        'estado_integridad',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'atributos_json' => 'array',
        'fecha_entrega' => 'date',
        'fecha_vencimiento_calendario' => 'date',
        'vida_util_dias_snapshot' => 'integer',
        'devuelto_at' => 'date',
        'cantidad_original' => 'integer',
        'cantidad_pendiente_devolucion' => 'integer',
        'es_legacy' => 'boolean',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function epp(): BelongsTo
    {
        return $this->belongsTo(EppRegistro::class, 'epp_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por_usuario_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cerrado_por_usuario_id');
    }

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(EppOperacion::class, 'operacion_id');
    }

    public function operacionDetalle(): BelongsTo
    {
        return $this->belongsTo(EppOperacionDetalle::class, 'operacion_detalle_id');
    }
}
