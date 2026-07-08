<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RQMinaActividadTransporteEvento extends Model
{
    public const TIPO_REGISTRO = 'REGISTRO_REQUERIMIENTO';
    public const TIPO_CAMBIO = 'CAMBIO_REQUERIMIENTO';
    public const TIPO_RETIRO = 'RETIRO_TRANSPORTE';

    protected $table = 'rq_mina_actividad_transporte_eventos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'transporte_id',
        'tipo',
        'estado_anterior',
        'estado_nuevo',
        'descripcion',
        'transporte_snapshot',
        'fecha_evento',
        'usuario_id',
    ];

    protected $casts = [
        'transporte_snapshot' => 'array',
        'fecha_evento' => 'datetime',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
