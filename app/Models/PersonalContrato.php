<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalContrato extends Model
{
    protected $table = 'personal_contratos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'contrato_numero',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'motivo_cese',
        'activado_at',
        'activado_by_usuario_id',
        'cerrado_at',
        'cerrado_by_usuario_id',
        'origen_contrato_id',
        'personal_ficha_id',
        'snapshot_inicial_json',
        'snapshot_json',
    ];

    protected $casts = [
        'contrato_numero' => 'integer',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'activado_at' => 'datetime',
        'cerrado_at' => 'datetime',
        'snapshot_inicial_json' => 'array',
        'snapshot_json' => 'array',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function activadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'activado_by_usuario_id');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'cerrado_by_usuario_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origen_contrato_id');
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(PersonalFicha::class, 'personal_ficha_id');
    }
}
