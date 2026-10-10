<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalCursoRegistro extends Model
{
    protected $table = 'personal_curso_registros';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = [
        'fecha_programacion' => 'date', 'fecha_realizacion' => 'date',
        'fecha_vencimiento' => 'date', 'nota' => 'decimal:2', 'catalogo_snapshot' => 'array',
    ];

    public function intentosMineros(): HasMany
    {
        return $this->hasMany(PersonalMinaExamenIntento::class, 'personal_curso_registro_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function emoCita(): BelongsTo
    {
        return $this->belongsTo(PersonalEmoCita::class, 'emo_cita_id');
    }
}
