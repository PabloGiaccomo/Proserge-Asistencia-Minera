<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParadaHerramientaItem extends Model
{
    public const TIPO_BASE = 'BASE';
    public const TIPO_ADICIONAL = 'ADICIONAL';

    public const CATEGORIA_HERRAMIENTA = 'HERRAMIENTA';
    public const CATEGORIA_CONSUMIBLE = 'CONSUMIBLE';

    public const RECEPCION_PENDIENTE = 'PENDIENTE';
    public const RECEPCION_COMPLETO = 'COMPLETO';
    public const RECEPCION_INCOMPLETO = 'INCOMPLETO';
    public const RECEPCION_NO_LLEGO = 'NO_LLEGO';

    protected $table = 'parada_herramienta_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_id',
        'tipo',
        'categoria',
        'descripcion',
        'cantidad_solicitada',
        'cantidad_entregada',
        'cantidad_recibida',
        'unidad',
        'observaciones',
        'incidencia_durante_parada',
        'pedido_solicitado_at',
        'pedido_llego_at',
        'recepcion_estado',
        'recepcion_fecha',
        'recepcion_observacion',
        'recepcion_registrada_at',
        'recepcion_registrada_por_usuario_id',
        'comentario_cambio_previo',
        'orden',
    ];

    protected $casts = [
        'cantidad_solicitada' => 'integer',
        'cantidad_entregada' => 'integer',
        'cantidad_recibida' => 'integer',
        'pedido_solicitado_at' => 'date',
        'pedido_llego_at' => 'date',
        'recepcion_fecha' => 'date',
        'recepcion_registrada_at' => 'datetime',
        'orden' => 'integer',
    ];

    public static function recepcionEstados(): array
    {
        return [
            self::RECEPCION_PENDIENTE,
            self::RECEPCION_COMPLETO,
            self::RECEPCION_INCOMPLETO,
            self::RECEPCION_NO_LLEGO,
        ];
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(ParadaHerramientaGrupo::class, 'grupo_id');
    }
}
