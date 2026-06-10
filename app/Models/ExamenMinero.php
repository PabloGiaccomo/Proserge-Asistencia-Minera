<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamenMinero extends Model
{
    protected $table = 'examenes_mineros';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'nombre',
        'descripcion',
        'tipo',
        'requiere_lugar',
        'lugar',
        'empresa_paga',
        'precio',
        'moneda',
        'precio_desde',
        'tiene_vigencia',
        'vigencia_dias',
        'permite_reintento',
        'max_intentos',
        'critico',
        'desaprueba_finaliza_proceso',
        'requiere_nota',
        'nota_minima',
        'solo_resultado',
        'permite_convalidacion',
        'observacion',
        'activo',
        'orden',
        'created_by_usuario_id',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'requiere_lugar' => 'boolean',
        'empresa_paga' => 'boolean',
        'precio' => 'decimal:2',
        'precio_desde' => 'date',
        'tiene_vigencia' => 'boolean',
        'vigencia_dias' => 'integer',
        'permite_reintento' => 'boolean',
        'max_intentos' => 'integer',
        'critico' => 'boolean',
        'desaprueba_finaliza_proceso' => 'boolean',
        'requiere_nota' => 'boolean',
        'nota_minima' => 'decimal:2',
        'solo_resultado' => 'boolean',
        'permite_convalidacion' => 'boolean',
        'activo' => 'boolean',
        'orden' => 'integer',
    ];

    public function requisitosMina(): HasMany
    {
        return $this->hasMany(MinaRequisito::class, 'examen_id');
    }

    public function precios(): HasMany
    {
        return $this->hasMany(ExamenMineroPrecio::class, 'examen_id')
            ->orderByDesc('fecha_inicio');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'created_by_usuario_id');
    }

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'updated_by_usuario_id');
    }
}
