<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Falta extends Model
{
    protected $table = 'faltas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'trabajador_id',
        'fecha',
        'motivo',
        'descripcion',
        'observaciones',
        'estado',
        'motivo_correccion',
        'motivo_anulacion',
        'corregido_por_usuario_id',
        'anulado_por_usuario_id',
        'corregido_at',
        'anulado_at',
        'registrada_por_id',
        'asistencia_encabezado_id',
        'asistencia_detalle_id',
        'destino_tipo',
        'destino_id',
    ];
}
