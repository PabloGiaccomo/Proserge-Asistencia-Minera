<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EppReserva extends Model
{
    use HasUuids;

    public const ESTADO_ACTIVA = 'ACTIVA';

    public const ESTADO_CONSUMIDA = 'CONSUMIDA';

    public const ESTADO_LIBERADA = 'LIBERADA';

    public const ESTADO_EXPIRADA = 'EXPIRADA';

    protected $table = 'epp_reservas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'existencia_id',
        'operacion_id',
        'operacion_detalle_id',
        'cantidad',
        'estado',
        'expires_at',
        'liberada_at',
        'consumida_at',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'expires_at' => 'datetime',
        'liberada_at' => 'datetime',
        'consumida_at' => 'datetime',
    ];

    public function existencia(): BelongsTo
    {
        return $this->belongsTo(EppExistencia::class, 'existencia_id');
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
