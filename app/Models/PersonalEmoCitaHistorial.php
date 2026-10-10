<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalEmoCitaHistorial extends Model
{
    use HasUuids;

    protected $table = 'personal_emo_cita_historial';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'emo_cita_id', 'evento', 'valores_anteriores_json', 'valores_nuevos_json',
        'usuario_id', 'origen', 'fecha_cambio',
    ];

    protected $casts = [
        'valores_anteriores_json' => 'array',
        'valores_nuevos_json' => 'array',
        'fecha_cambio' => 'datetime',
    ];

    public function cita(): BelongsTo
    {
        return $this->belongsTo(PersonalEmoCita::class, 'emo_cita_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
