<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EppAlmacen extends Model
{
    use HasUuids;

    public const ESTADO_ACTIVO = 'ACTIVO';

    public const ESTADO_INACTIVO = 'INACTIVO';

    protected $table = 'epp_almacenes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'nombre',
        'mina_id',
        'estado',
    ];

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function existencias(): HasMany
    {
        return $this->hasMany(EppExistencia::class, 'almacen_id');
    }

    public function operaciones(): HasMany
    {
        return $this->hasMany(EppOperacion::class, 'almacen_id');
    }
}
