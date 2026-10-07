<?php

namespace App\Models;

use App\Models\Builders\EppInventarioQueryBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EppInventarioMovimiento extends Model
{
    use HasUuids;

    public const TIPO_APERTURA = 'APERTURA';

    public const TIPO_ENTRADA = 'ENTRADA';

    public const TIPO_SALIDA = 'SALIDA';

    public const TIPO_DEVOLUCION = 'DEVOLUCION';

    public const TIPO_AJUSTE = 'AJUSTE';

    public const TIPO_RESERVA = 'RESERVA';

    public const TIPO_LIBERACION = 'LIBERACION';

    public const TIPO_BAJA = 'BAJA';

    public const TIPO_CORRECCION = 'CORRECCION';

    public const DESTINO_DISPONIBLE = 'DISPONIBLE';

    public const DESTINO_CUARENTENA = 'CUARENTENA';

    public const DESTINO_BAJA = 'BAJA';

    protected $table = 'epp_inventario_movimientos';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'existencia_id',
        'almacen_id',
        'epp_id',
        'operacion_id',
        'operacion_detalle_id',
        'tipo',
        'cantidad',
        'saldo_anterior',
        'saldo_posterior',
        'destino',
        'actor_usuario_id',
        'origen',
        'motivo',
        'snapshot_json',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'saldo_anterior' => 'integer',
        'saldo_posterior' => 'integer',
        'snapshot_json' => 'array',
        'created_at' => 'datetime',
    ];

    protected function newBaseQueryBuilder(): EppInventarioQueryBuilder
    {
        $connection = $this->getConnection();

        return new EppInventarioQueryBuilder($connection, $connection->getQueryGrammar(), $connection->getPostProcessor());
    }

    public function existencia(): BelongsTo
    {
        return $this->belongsTo(EppExistencia::class, 'existencia_id');
    }

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(EppAlmacen::class, 'almacen_id');
    }

    public function epp(): BelongsTo
    {
        return $this->belongsTo(EppRegistro::class, 'epp_id');
    }

    public function operacion(): BelongsTo
    {
        return $this->belongsTo(EppOperacion::class, 'operacion_id');
    }

    public function operacionDetalle(): BelongsTo
    {
        return $this->belongsTo(EppOperacionDetalle::class, 'operacion_detalle_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actor_usuario_id');
    }
}
