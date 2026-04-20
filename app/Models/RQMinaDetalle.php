<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQMinaDetalle extends Model
{
    protected $table = 'rq_mina_detalle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'puesto',
        'cantidad',
        'cantidad_atendida',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(RQProsergeDetalle::class, 'rq_mina_detalle_id');
    }
}
