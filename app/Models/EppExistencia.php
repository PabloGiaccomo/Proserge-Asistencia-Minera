<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EppExistencia extends Model
{
    use HasUuids;

    public const ESTADO_NO_CONCILIADO = 'STOCK_NO_CONCILIADO';

    protected $table = 'epp_existencias';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'almacen_id',
        'epp_id',
        'variante_clave',
        'atributos_json',
        'cantidad_disponible',
        'cantidad_reservada',
        'cantidad_cuarentena',
        'conciliado_at',
    ];

    protected $casts = [
        'atributos_json' => 'array',
        'cantidad_disponible' => 'integer',
        'cantidad_reservada' => 'integer',
        'cantidad_cuarentena' => 'integer',
        'conciliado_at' => 'datetime',
    ];

    public function almacen(): BelongsTo
    {
        return $this->belongsTo(EppAlmacen::class, 'almacen_id');
    }

    public function epp(): BelongsTo
    {
        return $this->belongsTo(EppRegistro::class, 'epp_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(EppReserva::class, 'existencia_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(EppInventarioMovimiento::class, 'existencia_id');
    }
}
