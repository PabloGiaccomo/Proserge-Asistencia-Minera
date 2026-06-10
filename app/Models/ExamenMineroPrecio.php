<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamenMineroPrecio extends Model
{
    protected $table = 'examen_minero_precios';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'examen_id',
        'precio',
        'moneda',
        'fecha_inicio',
        'fecha_fin',
        'observacion',
        'usuario_id',
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenMinero::class, 'examen_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
