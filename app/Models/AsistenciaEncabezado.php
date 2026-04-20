<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsistenciaEncabezado extends Model
{
    protected $table = 'asistencia_encabezado';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'grupo_trabajo_id',
        'fecha',
        'hora_ingreso',
        'mina_id',
        'destino_tipo',
        'destino_id',
        'reporte_suceso',
        'supervisor_id',
        'actividad_realizada',
        'estado',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function grupoTrabajo(): BelongsTo
    {
        return $this->belongsTo(GrupoTrabajo::class, 'grupo_trabajo_id');
    }

    public function mina(): BelongsTo
    {
        return $this->belongsTo(Mina::class, 'mina_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'supervisor_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(AsistenciaDetalle::class, 'asistencia_id');
    }
}
