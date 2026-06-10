<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonalMinaExamen extends Model
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_PROGRAMADO = 'PROGRAMADO';
    public const ESTADO_APROBADO = 'APROBADO';
    public const ESTADO_DESAPROBADO = 'DESAPROBADO';
    public const ESTADO_VIGENTE = 'VIGENTE';
    public const ESTADO_POR_VENCER = 'POR_VENCER';
    public const ESTADO_VENCIDO = 'VENCIDO';
    public const ESTADO_NO_APLICA = 'NO_APLICA';
    public const ESTADO_OBSERVADO = 'OBSERVADO';
    public const ESTADO_CONVALIDADO = 'CONVALIDADO';

    protected $table = 'personal_mina_examenes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_mina_id',
        'mina_requisito_id',
        'examen_id',
        'nombre_snapshot',
        'lugar_snapshot',
        'precio_snapshot',
        'tiene_vigencia_snapshot',
        'vigencia_dias_snapshot',
        'obligatorio_snapshot',
        'critico_snapshot',
        'permite_reintento_snapshot',
        'max_intentos_snapshot',
        'requiere_nota_snapshot',
        'nota_minima_snapshot',
        'estado',
        'resultado',
        'nota_obtenida',
        'fecha_programacion',
        'fecha_realizacion',
        'fecha_vencimiento',
        'es_convalidado',
        'examen_origen_convalidado_id',
        'mina_origen_convalidacion_id',
        'fecha_aprobacion_origen',
        'fecha_convalidacion',
        'usuario_convalidacion_id',
        'observacion',
        'usuario_actualizacion_id',
        'fecha_actualizacion',
    ];

    protected $casts = [
        'precio_snapshot' => 'decimal:2',
        'tiene_vigencia_snapshot' => 'boolean',
        'vigencia_dias_snapshot' => 'integer',
        'obligatorio_snapshot' => 'boolean',
        'critico_snapshot' => 'boolean',
        'permite_reintento_snapshot' => 'boolean',
        'max_intentos_snapshot' => 'integer',
        'requiere_nota_snapshot' => 'boolean',
        'nota_minima_snapshot' => 'decimal:2',
        'nota_obtenida' => 'decimal:2',
        'fecha_programacion' => 'date',
        'fecha_realizacion' => 'date',
        'fecha_vencimiento' => 'date',
        'es_convalidado' => 'boolean',
        'fecha_aprobacion_origen' => 'date',
        'fecha_convalidacion' => 'datetime',
        'fecha_actualizacion' => 'datetime',
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(PersonalMina::class, 'personal_mina_id');
    }

    public function requisitoMina(): BelongsTo
    {
        return $this->belongsTo(MinaRequisito::class, 'mina_requisito_id');
    }

    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenMinero::class, 'examen_id');
    }

    public function intentos(): HasMany
    {
        return $this->hasMany(PersonalMinaExamenIntento::class, 'personal_mina_examen_id')
            ->orderBy('numero_intento');
    }

    public function origenConvalidado(): BelongsTo
    {
        return $this->belongsTo(self::class, 'examen_origen_convalidado_id');
    }

    public function usuarioActualizacion(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_actualizacion_id');
    }
}
