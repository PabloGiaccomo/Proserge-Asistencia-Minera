<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalBloqueo extends Model
{
    protected $table = 'personal_bloqueo';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'personal_id',
        'tipo',
        'fecha_inicio',
        'fecha_fin',
        'motivo',
        'detalle',
        'bloqueado_por_id',
        'estado',
        'visible_para_planner',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'visible_para_planner' => 'boolean',
    ];

    public function personal(): BelongsTo
    {
        return $this->belongsTo(Personal::class, 'personal_id');
    }

    public function bloqueadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'bloqueado_por_id');
    }

    public function tipoLabel(): string
    {
        return match (strtolower((string) $this->tipo)) {
            'vacaciones' => 'Vacaciones',
            'descanso_medico' => 'Descanso médico',
            'inhabilitado' => 'Inhabilitado',
            'restriccion_temporal' => 'Restricción temporal',
            default => ucfirst(str_replace('_', ' ', (string) $this->tipo)),
        };
    }
}
