<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EppOperacionDetalle extends Model
{
    use HasUuids;

    public const ESTADO_PENDIENTE = 'PENDIENTE';

    public const ESTADO_RESERVADO = 'RESERVADO';

    public const ESTADO_ENTREGADO = 'ENTREGADO';

    public const ESTADO_DEVUELTO = 'DEVUELTO';

    public const ESTADO_CONSUMIDO = 'CONSUMIDO';

    public const ESTADO_NO_DISPONIBLE = 'NO_DISPONIBLE';

    public const ESTADO_RECHAZADO = 'RECHAZADO';

    public const ESTADO_PERDIDO = 'PERDIDO';

    public const ESTADO_ANULADO = 'ANULADO';

    public const CONDICION_BUENO = 'BUENO';

    public const CONDICION_DESGASTADO = 'DESGASTADO';

    public const CONDICION_DANADO = 'DANADO';

    public const CONDICION_INCOMPLETO = 'INCOMPLETO';

    public const CONDICION_OTRO = 'OTRO';

    protected $table = 'epp_operacion_detalles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'operacion_id',
        'epp_solicitado_id',
        'epp_id',
        'existencia_id',
        'entrega_origen_id',
        'detalle_origen_id',
        'cantidad_solicitada',
        'cantidad_procesada',
        'cantidad_pendiente',
        'estado',
        'talla',
        'color',
        'atributos_json',
        'variante_clave',
        'politica_devolucion_snapshot',
        'control_inventario_snapshot',
        'precio_unitario_snapshot',
        'condicion_devolucion',
        'destino_inventario',
        'motivo',
        'snapshot_json',
    ];

    protected $casts = [
        'cantidad_solicitada' => 'integer',
        'cantidad_procesada' => 'integer',
        'cantidad_pendiente' => 'integer',
        'atributos_json' => 'array',
        'control_inventario_snapshot' => 'boolean',
        'precio_unitario_snapshot' => 'decimal:2',
        'snapshot_json' => 'array',
    ];

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(EppOperacion::class, 'operacion_id');
    }

    public function eppSolicitado(): BelongsTo
    {
        return $this->belongsTo(EppRegistro::class, 'epp_solicitado_id');
    }

    public function epp(): BelongsTo
    {
        return $this->belongsTo(EppRegistro::class, 'epp_id');
    }

    public function existencia(): BelongsTo
    {
        return $this->belongsTo(EppExistencia::class, 'existencia_id');
    }

    public function entregaOrigen(): BelongsTo
    {
        return $this->belongsTo(EppEntrega::class, 'entrega_origen_id');
    }

    public function detalleOrigen(): BelongsTo
    {
        return $this->belongsTo(EppOperacionDetalle::class, 'detalle_origen_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(EppReserva::class, 'operacion_detalle_id');
    }
}
