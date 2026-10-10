<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalEmoCita extends Model
{
    use HasUuids;

    public const ESTADO_PROGRAMADA = 'PROGRAMADA';

    public const ESTADO_COMPLETADA = 'COMPLETADA';

    public const ESTADO_ANULADA = 'ANULADA';

    protected $table = 'personal_emo_citas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'personal_id', 'clinica_id', 'clinica_nombre_snapshot',
        'fecha_programacion', 'fecha_realizacion', 'estado', 'revision', 'resultado_emo', 'detalle_resultado_emo',
        'origen', 'usuario_programacion_id', 'usuario_resultado_id', 'usuario_actualizacion_id',
    ];

    protected $casts = [
        'revision' => 'integer',
        'fecha_programacion' => 'date',
        'fecha_realizacion' => 'date',
    ];

    public function clinica(): BelongsTo
    {
        return $this->belongsTo(ClinicaEmo::class, 'clinica_id');
    }

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function intentos(): HasMany
    {
        return $this->hasMany(PersonalMinaExamenIntento::class, 'emo_cita_id');
    }

    public function cursos(): HasMany
    {
        return $this->hasMany(PersonalCursoRegistro::class, 'emo_cita_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(PersonalEmoCitaHistorial::class, 'emo_cita_id')->orderBy('fecha_cambio');
    }

    public function programadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_programacion_id');
    }

    public function completadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_resultado_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_actualizacion_id');
    }
}
