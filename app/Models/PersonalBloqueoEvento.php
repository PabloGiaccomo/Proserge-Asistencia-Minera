<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalBloqueoEvento extends Model
{
    public const EDITADO = 'EDITADO';
    public const ANULADO = 'ANULADO';

    protected $table = 'personal_bloqueo_eventos';
    public $incrementing = false;
    public $timestamps = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'personal_bloqueo_id', 'actor_id', 'tipo', 'motivo_decision', 'antes', 'despues', 'ocurrido_at',
    ];

    protected $hidden = ['motivo_decision', 'antes', 'despues'];

    protected $casts = [
        'motivo_decision' => 'encrypted',
        'antes' => 'encrypted:array',
        'despues' => 'encrypted:array',
        'ocurrido_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'actor_id');
    }

    public function bloqueo(): BelongsTo
    {
        return $this->belongsTo(PersonalBloqueo::class, 'personal_bloqueo_id');
    }
}
