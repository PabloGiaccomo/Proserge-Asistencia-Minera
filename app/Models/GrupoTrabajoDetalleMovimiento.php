<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrupoTrabajoDetalleMovimiento extends Model
{
    protected $table = 'grupo_trabajo_detalle_movimientos';

    public $timestamps = false;

    protected $fillable = [
        'grupo_trabajo_id', 'grupo_trabajo_detalle_id', 'personal_id', 'actor_id', 'grupo_destino_id',
        'asignacion_anterior_id', 'asignacion_nueva_id', 'tipo', 'origen', 'estado_anterior', 'estado_nuevo',
        'motivo', 'ocurrido_at',
    ];

    protected $casts = ['ocurrido_at' => 'datetime:Y-m-d H:i:s'];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actor_id');
    }
}
