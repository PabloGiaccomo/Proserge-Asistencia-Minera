<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RQMinaDetalleCambio extends Model
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';

    public const TIPO_PUESTO_AGREGADO = 'PUESTO_AGREGADO';
    public const TIPO_PUESTO_RETIRADO = 'PUESTO_RETIRADO';
    public const TIPO_CANTIDAD_AUMENTADA = 'CANTIDAD_AUMENTADA';
    public const TIPO_CANTIDAD_REDUCIDA = 'CANTIDAD_REDUCIDA';

    protected $table = 'rq_mina_detalle_cambios';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'rq_mina_detalle_id',
        'rq_proserge_id',
        'puesto',
        'tipo',
        'cantidad_anterior',
        'cantidad_nueva',
        'asignaciones_retiradas',
        'mensaje',
        'estado',
        'created_by_usuario_id',
    ];

    protected $casts = [
        'cantidad_anterior' => 'integer',
        'cantidad_nueva' => 'integer',
        'asignaciones_retiradas' => 'integer',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function rqMinaDetalle(): BelongsTo
    {
        return $this->belongsTo(RQMinaDetalle::class, 'rq_mina_detalle_id');
    }

    public function rqProserge(): BelongsTo
    {
        return $this->belongsTo(RQProserge::class, 'rq_proserge_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }
}
