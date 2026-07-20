<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EppEntrega extends Model
{
    public const ESTADO_ENTREGADO = 'ENTREGADO';
    public const ESTADO_CAMBIADO = 'CAMBIADO';
    public const ESTADO_DEVUELTO = 'DEVUELTO';
    public const ESTADO_USO_INCORRECTO = 'USO_INCORRECTO';
    public const ESTADO_PERDIDA_OLVIDO = 'PERDIDA_OLVIDO';

    protected $table = 'epp_entregas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'epp_id',
        'cantidad',
        'talla',
        'color',
        'atributos_json',
        'fecha_entrega',
        'fecha_vencimiento_calendario',
        'vida_util_dias_snapshot',
        'estado',
        'motivo_cambio',
        'observacion',
        'devuelto_at',
        'registrado_por_usuario_id',
        'cerrado_por_usuario_id',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'atributos_json' => 'array',
        'fecha_entrega' => 'date',
        'fecha_vencimiento_calendario' => 'date',
        'vida_util_dias_snapshot' => 'integer',
        'devuelto_at' => 'date',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function epp(): BelongsTo
    {
        return $this->belongsTo(EppRegistro::class, 'epp_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por_usuario_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cerrado_por_usuario_id');
    }
}
