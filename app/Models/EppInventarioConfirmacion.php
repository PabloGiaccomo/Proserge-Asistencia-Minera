<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EppInventarioConfirmacion extends Model
{
    use HasUuids;

    protected $table = 'epp_inventario_confirmaciones';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'actor_usuario_id', 'accion', 'payload_json', 'expires_at', 'confirmado_at', 'resultado_json'];

    protected $casts = [
        'payload_json' => 'array', 'resultado_json' => 'array',
        'expires_at' => 'datetime', 'confirmado_at' => 'datetime',
    ];
}
