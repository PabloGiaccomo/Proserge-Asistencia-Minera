<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalMinaHistorial extends Model
{
    protected $table = 'personal_mina_historial';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_mina_id',
        'estado_anterior',
        'estado_nuevo',
        'observacion',
        'usuario_id',
        'fecha_cambio',
    ];

    protected $casts = [
        'fecha_cambio' => 'datetime',
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(PersonalMina::class, 'personal_mina_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
