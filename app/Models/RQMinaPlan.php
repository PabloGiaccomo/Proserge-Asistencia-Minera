<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQMinaPlan extends Model
{
    public const ESTADO_BORRADOR = 'BORRADOR';
    public const ESTADO_VIGENTE = 'VIGENTE';
    public const ESTADO_ARCHIVADO = 'ARCHIVADO';

    public const CODIGO_DEFAULT = 'PLAN-001';

    protected $table = 'rq_mina_planes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'codigo',
        'nombre',
        'version',
        'fecha_inicio',
        'fecha_fin',
        'semana_referencia',
        'estado',
        'observaciones',
        'created_by_usuario_id',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'version' => 'integer',
    ];

    public static function estados(): array
    {
        return [
            self::ESTADO_BORRADOR,
            self::ESTADO_VIGENTE,
            self::ESTADO_ARCHIVADO,
        ];
    }

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(RQMinaActividadGrupo::class, 'rq_mina_plan_id')->orderBy('orden');
    }

    public function gruposManPower(): HasMany
    {
        return $this->hasMany(GrupoTrabajo::class, 'rq_mina_plan_id');
    }

    public function requerimientosTransporte(): HasMany
    {
        return $this->hasMany(RQMinaActividadTransporte::class, 'rq_mina_plan_id')->orderBy('orden');
    }

    public function serviciosTransporte(): HasMany
    {
        return $this->hasMany(TransporteServicio::class, 'rq_mina_plan_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by_usuario_id');
    }
}
