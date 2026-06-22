<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalIngreso extends Model
{
    public const ESTADO_RECIBIDA = 'FICHA_RECIBIDA';
    public const ESTADO_FALTA_REVISION = 'FALTA_REVISION';
    public const ESTADO_ACEPTADA = 'ACEPTADA';
    public const ESTADO_CONTRATO_NO_FIRMADO = 'CONTRATO_NO_FIRMADO';

    protected $table = 'personal_ingresos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'estado',
        'tipo_documento',
        'numero_documento',
        'personal_existente_id',
        'personal_creado_id',
        'datos_json',
        'familiares_json',
        'firma_base64',
        'huella_path',
        'observaciones_revision',
        'submitted_at',
        'reviewed_at',
        'reviewed_by_usuario_id',
    ];

    protected $casts = [
        'datos_json' => 'array',
        'familiares_json' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function archivos(): HasMany
    {
        return $this->hasMany(PersonalIngresoArchivo::class, 'personal_ingreso_id');
    }

    public function personalExistente(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_existente_id');
    }

    public function personalCreado(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_creado_id');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'reviewed_by_usuario_id');
    }
}
