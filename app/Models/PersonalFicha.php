<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PersonalFicha extends Model
{
    public const ESTADO_PENDIENTE = 'PENDIENTE_COMPLETAR_FICHA';
    public const ESTADO_ENVIADA = 'FICHA_ENVIADA';
    public const ESTADO_APROBADO = 'APROBADO';
    public const ESTADO_OBSERVADO = 'OBSERVADO';
    public const ESTADO_RECHAZADO = 'RECHAZADO';
    public const ESTADO_LINK_VENCIDO = 'LINK_VENCIDO';

    protected $table = 'personal_fichas';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'estado',
        'tipo_documento',
        'numero_documento',
        'macro_tipo_contrato',
        'macro_original_nombre',
        'macro_original_path',
        'datos_detectados_json',
        'datos_json',
        'campos_verificacion_json',
        'advertencias_json',
        'firma_base64',
        'huella_path',
        'created_by_usuario_id',
        'submitted_at',
        'approved_at',
        'approved_by_usuario_id',
        'observed_at',
        'rejected_at',
        'observaciones_revision',
    ];

    protected $casts = [
        'datos_detectados_json' => 'array',
        'datos_json' => 'array',
        'campos_verificacion_json' => 'array',
        'advertencias_json' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'observed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'approved_by_usuario_id');
    }

    public function link(): HasOne
    {
        return $this->hasOne(PersonalFichaLink::class, 'personal_ficha_id')->latestOfMany();
    }

    public function familiares(): HasMany
    {
        return $this->hasMany(PersonalFichaFamiliar::class, 'personal_ficha_id');
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(PersonalFichaArchivo::class, 'personal_ficha_id');
    }
}
