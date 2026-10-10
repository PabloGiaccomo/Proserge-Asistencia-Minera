<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamenMineroCategoriaHistorial extends Model
{
    use HasUuids;

    protected $table = 'examen_minero_categoria_historial';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'examen_id', 'categoria_anterior', 'categoria_nueva', 'usuario_id', 'motivo', 'fecha_cambio',
    ];

    protected $casts = ['fecha_cambio' => 'datetime'];

    public function examen(): BelongsTo
    {
        return $this->belongsTo(ExamenMinero::class, 'examen_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
