<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalMina extends Model
{
    public const ESTADO_EN_PROCESO = 'EN_PROCESO';
    public const ESTADO_HABILITADO = 'HABILITADO';
    public const ESTADO_NO_HABILITADO = 'NO_HABILITADO';
    public const ESTADO_OBSERVADO = 'OBSERVADO';
    public const ESTADO_FINALIZADO_POR_DESAPROBACION = 'FINALIZADO_POR_DESAPROBACION';

    protected $table = 'personal_mina';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'mina_id',
        'estado',
        'estado_habilitacion',
        'fecha_asignacion',
        'fecha_inicio_proceso',
        'fecha_habilitacion',
        'observacion',
        'activo',
        'usuario_actualizacion_id',
    ];

    protected $casts = [
        'fecha_asignacion' => 'date',
        'fecha_inicio_proceso' => 'date',
        'fecha_habilitacion' => 'date',
        'activo' => 'boolean',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_actualizacion_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(PersonalMinaHistorial::class, 'personal_mina_id')
            ->orderByDesc('fecha_cambio')
            ->orderByDesc('created_at');
    }

    public function examenes(): HasMany
    {
        return $this->hasMany(PersonalMinaExamen::class, 'personal_mina_id')
            ->orderBy('nombre_snapshot');
    }

    public function estadoHabilitacionActual(): string
    {
        return strtoupper((string) ($this->estado_habilitacion ?: $this->estado ?: self::ESTADO_EN_PROCESO));
    }
}
