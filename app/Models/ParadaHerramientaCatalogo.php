<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ParadaHerramientaCatalogo extends Model
{
    protected $table = 'parada_herramienta_catalogos';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'categoria',
        'descripcion',
        'descripcion_normalizada',
        'unidad',
        'unidad_normalizada',
        'activo',
        'created_by_usuario_id',
        'updated_by_usuario_id',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function observaciones(): HasMany
    {
        return $this->hasMany(ParadaHerramientaCatalogoObservacion::class, 'catalogo_id')
            ->orderByDesc('usos')
            ->orderByDesc('updated_at');
    }
}
