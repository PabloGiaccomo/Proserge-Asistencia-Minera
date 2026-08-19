<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransporteServicioEvento extends Model
{
    protected $table = 'transporte_servicio_eventos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'transporte_servicio_id',
        'tipo',
        'estado_anterior',
        'estado_nuevo',
        'snapshot',
        'observacion',
        'usuario_id',
        'fecha_evento',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'fecha_evento' => 'datetime',
    ];

    public function servicio(): BelongsTo
    {
        return $this->belongsTo(TransporteServicio::class, 'transporte_servicio_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
