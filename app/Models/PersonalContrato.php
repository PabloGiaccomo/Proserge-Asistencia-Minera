<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalContrato extends Model
{
    public const ESTADO_PREPARACION = 'PREPARACION';
    public const ESTADO_ACTIVO = 'ACTIVO';
    public const ESTADO_CERRADO = 'CERRADO';
    public const ESTADO_CESADO = 'CESADO';
    public const ESTADO_NO_RENOVADO = 'NO_RENOVADO';
    public const ESTADO_ANULADO = 'ANULADO';
    public const MOVIMIENTO_RENOVACION = 'RENOVACION';
    public const MOVIMIENTO_REINGRESO = 'REINGRESO';
    public const DECISION_PENDIENTE = 'PENDIENTE';
    public const DECISION_EN_EVALUACION = 'EN_EVALUACION';
    public const DECISION_RENOVAR = 'RENOVAR';
    public const DECISION_NO_RENOVAR = 'NO_RENOVAR';
    public const DECISION_RENOVACION_PREPARADA = 'RENOVACION_PREPARADA';
    public const DECISION_NO_RENOVADO = 'NO_RENOVADO';
    public const MOTIVO_BAJO_DESEMPENO = 'BAJO_DESEMPENO';
    public const MOTIVO_FIN_NECESIDAD_OPERATIVA = 'FIN_NECESIDAD_OPERATIVA';
    public const MOTIVO_DECISION_AREA = 'DECISION_AREA';
    public const MOTIVO_RENUNCIA = 'RENUNCIA';
    public const MOTIVO_FALTA_DOCUMENTACION = 'FALTA_DOCUMENTACION';
    public const MOTIVO_NO_APTO_MINA = 'NO_APTO_MINA';
    public const MOTIVO_OTRO = 'OTRO';
    public const CESE_NO_RENOVACION_CONTRATO = 'NO_RENOVACION_CONTRATO';
    public const CESE_RENUNCIA = 'RENUNCIA';
    public const CESE_FIN_CONTRATO = 'FIN_CONTRATO';
    public const CESE_DECISION_AREA = 'DECISION_AREA';
    public const CESE_OTRO = 'OTRO';

    protected $table = 'personal_contratos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'contrato_numero',
        'estado',
        'origen_registro',
        'es_historico',
        'archivo_pendiente_regularizacion',
        'observacion_historica',
        'tipo_contrato',
        'puesto',
        'area',
        'mina',
        'remuneracion',
        'costo_hora',
        'es_supervisor',
        'registrado_by_usuario_id',
        'fecha_inicio',
        'fecha_fin',
        'motivo_cese',
        'activado_at',
        'activado_by_usuario_id',
        'cerrado_at',
        'cerrado_by_usuario_id',
        'signed_at',
        'signed_by_usuario_id',
        'signed_contract_path',
        'signed_contract_original_name',
        'signed_contract_mime',
        'signed_contract_size',
        'anulado_at',
        'anulado_by_usuario_id',
        'motivo_anulacion',
        'origen_contrato_id',
        'tipo_movimiento',
        'observacion_renovacion',
        'estado_decision_renovacion',
        'decision_final',
        'motivo_no_renovacion',
        'observacion_decision',
        'fecha_decision',
        'usuario_decision_id',
        'fecha_cierre_no_renovacion',
        'usuario_cierre_no_renovacion_id',
        'observacion_cierre_no_renovacion',
        'motivo_cese_controlado',
        'observacion_cese_controlado',
        'fecha_cese_controlado',
        'personal_ficha_id',
        'snapshot_inicial_json',
        'snapshot_json',
    ];

    protected $casts = [
        'contrato_numero' => 'integer',
        'es_historico' => 'boolean',
        'archivo_pendiente_regularizacion' => 'boolean',
        'es_supervisor' => 'boolean',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'activado_at' => 'datetime',
        'cerrado_at' => 'datetime',
        'signed_at' => 'datetime',
        'signed_contract_size' => 'integer',
        'anulado_at' => 'datetime',
        'fecha_decision' => 'datetime',
        'fecha_cierre_no_renovacion' => 'datetime',
        'fecha_cese_controlado' => 'date',
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

    public function firmadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'signed_by_usuario_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'anulado_by_usuario_id');
    }

    public function decisionUsuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_decision_id');
    }

    public function cierreNoRenovacionUsuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_cierre_no_renovacion_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'origen_contrato_id');
    }

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(PersonalFicha::class, 'personal_ficha_id');
    }

    public function isEditable(): bool
    {
        return in_array(strtoupper((string) $this->estado), [
            self::ESTADO_PREPARACION,
            self::ESTADO_ACTIVO,
        ], true);
    }

    public function isHistoricalLocked(): bool
    {
        return !$this->isEditable();
    }

    public function hasSignedFile(): bool
    {
        return $this->signed_at !== null && trim((string) $this->signed_contract_path) !== '';
    }
}
