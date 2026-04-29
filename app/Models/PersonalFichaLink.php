<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalFichaLink extends Model
{
    public const ESTADO_ACTIVO = 'ACTIVO';
    public const ESTADO_ENVIADO = 'ENVIADO';
    public const ESTADO_VENCIDO = 'VENCIDO';
    public const ESTADO_INHABILITADO = 'INHABILITADO';

    protected $table = 'personal_ficha_links';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_ficha_id',
        'token_hash',
        'token_encrypted',
        'estado',
        'expires_at',
        'read_until',
        'submitted_at',
        'disabled_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'read_until' => 'datetime',
        'submitted_at' => 'datetime',
        'disabled_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(PersonalFicha::class, 'personal_ficha_id');
    }
}
