<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RQMinaActividadGrupo extends Model
{
    protected $table = 'rq_mina_actividad_grupos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'rq_mina_id',
        'rq_mina_plan_id',
        'area_operativa',
        'modulo',
        'nombre',
        'observaciones',
        'orden',
    ];

    protected $casts = [
        'orden' => 'integer',
    ];

    public function rqMina(): BelongsTo
    {
        return $this->belongsTo(RQMina::class, 'rq_mina_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RQMinaPlan::class, 'rq_mina_plan_id');
    }

    public function actividades(): HasMany
    {
        return $this->hasMany(RQMinaActividad::class, 'grupo_id')->orderBy('orden');
    }

    public function transportes(): HasMany
    {
        return $this->hasMany(RQMinaActividadTransporte::class, 'grupo_id')->orderBy('orden');
    }

    public function gruposManPower(): HasMany
    {
        return $this->hasMany(GrupoTrabajo::class, 'rq_mina_actividad_grupo_id');
    }

    public function serviciosTransporte(): BelongsToMany
    {
        return $this->belongsToMany(
            TransporteServicio::class,
            'transporte_servicio_alcances',
            'rq_mina_actividad_grupo_id',
            'transporte_servicio_id'
        )->whereNotNull('transporte_servicio_alcances.rq_mina_actividad_grupo_id');
    }
}
